# Real-Time Sync

## Overview

When a user has Daybook open in multiple browser tabs, each tab independently
saves the full ProseMirror document to the server on every change. The last tab
to save silently overwrites whatever the other tabs wrote. There is no conflict
detection, no versioning, and no cross-tab communication.

This spec adds real-time sync so that every open tab sees every change as it
happens. Edits flow through a central authority on the server, which serializes
them into a linear history and fans them out to all connected clients.

### Design constraints

- Only today's note is writable. Past notes are immutable and never need sync.
- This is a single-user app. "Multiple clients" means the same user in
  different tabs/devices, not multiple collaborators. The system should still be
  built on a proper OT foundation so it handles concurrent edits correctly, but
  we do not need presence indicators, cursors, or user colors.
- The database continues to store the full ProseMirror JSON document as the
  source of truth. Redis holds a transient step log for catch-up and rebasing.
- The app is self-hosted on a single machine. We do not need to design for
  horizontal scaling in this iteration.

---

## 1. Why Reverb Works

The initial instinct was that Laravel Reverb's assumptions about payloads might
not play well with ProseMirror steps. After investigation, this is not the case.

Reverb is a Pusher-protocol-compatible WebSocket server built on a ReactPHP
event loop. It imposes no constraints on event payload shape -- broadcast events
define their own payload via `broadcastWith()`, which returns an arbitrary
JSON-serializable array. ProseMirror steps serialize cleanly to JSON, so they
fit into a standard broadcast event without friction.

The architecture is straightforward:

1. **Client sends steps to the server via HTTP** (`POST /note/steps`).
2. **Server validates and accepts steps**, appends them to the Redis step log,
   applies them to the canonical document, and persists the updated document to
   the database.
3. **Server broadcasts an event via Reverb** containing the accepted steps and
   the new version number.
4. **Other clients receive the event** over WebSocket and apply the steps
   through ProseMirror's `collab` plugin, which handles rebasing any unconfirmed
   local steps.

This is the standard ProseMirror collab pattern. HTTP for writes, WebSocket for
reads. Reverb handles the WebSocket fan-out and channel authorization natively.

### What we get from Reverb

- Private channel authorization tied to Laravel's auth system -- a client can
  only subscribe to `private-note.{noteId}` if `NotePolicy@view` passes for
  their user.
- Laravel Echo React integration via `@laravel/echo-react` (`useEcho` hook).
- Built-in reconnection and heartbeat handling from the Pusher client library.
- No additional server process to manage beyond `php artisan reverb:start`.
- Future horizontal scaling via Reverb's built-in Redis pub/sub support.

---

## 2. ProseMirror Collab Module

The `prosemirror-collab` package implements a client-side plugin for
collaborative editing using operational transformation (OT). Its model:

- Each document has a **version number** (an integer starting at 0, or whatever
  version the document was at when the client loaded it).
- Every edit the user makes produces one or more **steps** (atomic document
  transformations like `ReplaceStep`, `AddMarkStep`, etc.).
- The client sends unconfirmed steps to a **central authority** (our server).
- The authority either accepts the steps (incrementing the version) or rejects
  them if they are based on a stale version.
- When the client receives accepted steps (from itself or others), it applies
  them through the collab plugin, which rebases any pending local steps on top
  of the incoming steps.

### Key functions from `prosemirror-collab`

| Function | Purpose |
|----------|---------|
| `collab({ version })` | Plugin factory. Pass the initial document version. |
| `sendableSteps(state)` | Returns `{ version, steps, clientID }` if there are unconfirmed local steps, or `null`. |
| `receiveTransaction(state, steps, clientIDs)` | Creates a transaction that applies confirmed steps from the authority and rebases local steps. |
| `getVersion(state)` | Returns the current confirmed version. |

---

## 3. Server-Side Architecture

### 3.1 Data Model Changes

Add a `version` column to the `notes` table via an **alter migration** (not a
fresh create). The existing `id`, `user_id`, `date`, `content`, and timestamp
columns are untouched. Existing note content is fully preserved.

```php
// database/migrations/xxxx_xx_xx_add_version_to_notes_table.php

Schema::table('notes', function (Blueprint $table) {
    $table->unsignedBigInteger('version')->default(0);
});
```

The `default(0)` ensures all existing rows are backfilled with version 0, which
is correct -- they have no step history and any client loading them will start
the collab plugin at version 0. New notes also start at version 0. Each batch
of accepted steps increments the version by the number of steps in the batch.
The version represents the total number of steps applied to the document since
creation.

### 3.2 Redis Step Log

Steps are stored in a Redis sorted set keyed by note, with the step's version as
the score. This allows efficient range queries for catch-up.

**Key format:** `note_steps:{user_id}:{date}`

Each member of the sorted set is a JSON-encoded object:

```json
{
    "version": 42,
    "step": { "stepType": "replace", "from": 10, "to": 15, "slice": { ... } },
    "clientID": "tab-abc123"
}
```

**TTL:** 1 hour. Set via `EXPIRE` after every write. This is a rolling window --
the TTL resets on each edit, so active documents retain their history. Steps
older than 1 hour are irrelevant because any client that has been disconnected
that long should do a full document reload.

**Why a sorted set:** `ZRANGEBYSCORE note_steps:{uid}:{date} {fromVersion} +inf`
gives us exactly the steps a stale client needs to catch up, ordered by version.

### 3.3 Step Acceptance Endpoint

```
POST /note/steps
```

**Request body:**

```json
{
    "version": 40,
    "steps": [
        { "stepType": "replace", "from": 10, "to": 10, "slice": { ... } },
        { "stepType": "replace", "from": 15, "to": 18, "slice": { ... } }
    ],
    "clientID": "tab-abc123"
}
```

**Server logic (pseudocode):**

```
1. Authenticate the user, determine today's date from timezone cookie.
2. Acquire a lock on the note (Redis lock or DB advisory lock).
3. Load the note from the database (content + version).
4. If request.version != note.version:
     - Return 409 Conflict with { version: note.version }.
       The client will fetch missing steps and rebase.
 5. Parse each step JSON into a ProseMirror Step object.
    (Use the prosemirror-transform PHP port, or -- more practically --
     apply the steps by reconstructing the document from JSON on the
     server. See section 3.4.)
 6. Apply each step to the document sequentially. If any step fails
    to apply, reject the entire batch with 400.
 7. Increment the note's version by the number of accepted steps.
 8. Persist the new document JSON and version to the database.
 9. Write each step to the Redis sorted set with its version as score.
10. Reset the Redis key TTL to 1 hour.
11. Release the lock.
12. Broadcast a NoteStepsAccepted event via Reverb.
13. Persist the user's timezone (from cookie) for background jobs.
14. Dispatch GenerateWeeklySummary and GenerateNoteEmbeddings with a
    30-second delay. Both jobs implement ShouldBeUnique, so rapid step
    batches during active typing will not queue duplicate jobs -- only
    the first dispatch in each uniqueness window actually enqueues.
    See section 6 for details.
15. Return 200 with { version: note.new_version }.
```

**Response (success):**

```json
{
    "version": 42
}
```

**Response (conflict):**

```json
{
    "version": 41,
    "steps": [ ... ],
    "clientIDs": [ ... ]
}
```

On a 409, the server includes the missing steps so the client can catch up in a
single round trip rather than making a separate GET request.

### 3.4 Applying Steps Server-Side

ProseMirror is a JavaScript library. We need to validate that the submitted steps
produce a valid document when applied to the current server-side document.

**Option A: Trust the client, validate the result.**

Accept the steps from the client and apply them naively by running the
ProseMirror step application in a small Node.js sidecar process. The sidecar
receives the current document JSON, the steps, and returns the resulting
document JSON (or an error). This is the most correct approach but adds a
Node.js dependency.

**Option B: Trust the client, skip step application.**

Since this is a single-user app, and the client is running ProseMirror with its
own schema validation, we can take a pragmatic shortcut: accept the steps as-is,
store them in Redis for fan-out, and rely on the *next full document save* to
persist the correct state. The client that sent the steps already has the
resulting document locally, so we ask it to also send the resulting document JSON
alongside the steps. The server stores this as the new canonical document.

This is the recommended approach for this project. It avoids a Node.js sidecar,
keeps the stack pure PHP, and is safe because:

- Only one user can edit (no malicious actors).
- The client is authoritative about its own document state.
- The version + lock mechanism prevents interleaving.

The request body becomes:

```json
{
    "version": 40,
    "steps": [ ... ],
    "clientID": "tab-abc123",
    "doc": { "type": "doc", "content": [ ... ] }
}
```

The server stores `doc` as the new `content` on the note and broadcasts only the
steps (not the full doc) to other clients.

### 3.5 Broadcast Event

```php
class NoteStepsAccepted implements ShouldBroadcastNow
{
    public function __construct(
        public Note $note,
        public int $version,
        public array $steps,
        public array $clientIDs,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("note.{$this->note->id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'version' => $this->version,
            'steps' => $this->steps,
            'clientIDs' => $this->clientIDs,
        ];
    }

    public function broadcastAs(): string
    {
        return 'steps.accepted';
    }
}
```

Uses `ShouldBroadcastNow` (not `ShouldBroadcast`) to bypass the queue and
minimize latency. Step fan-out must be fast -- going through the queue would add
unacceptable delay for real-time editing.

### 3.6 Channel Authorization

Channel authorization delegates to `NotePolicy` so ownership logic lives in one
place. The channel is keyed by `{noteId}`, which lets us load the note model and
run the policy check directly.

**Channel name:** `private-note.{noteId}`

Update the broadcast event (section 3.5) to use `note.{noteId}` as well -- the
note ID is available on every note row and is more natural than a composite
`userId.date` key.

```php
// routes/channels.php

use App\Models\Note;
use App\Models\User;

Broadcast::channel('note.{note}', function (User $user, Note $note) {
    return $user->can('view', $note);
});
```

This uses implicit model binding -- Laravel resolves `{note}` to a `Note`
instance automatically. The `can('view', $note)` call hits `NotePolicy@view`,
which checks `$user->id === $note->user_id`.

If the note doesn't exist yet (today's note hasn't been created), the channel
should still be subscribable. Handle this by falling back to a user-scoped
channel for today's date until the note is created:

```php
Broadcast::channel('note.{noteId}', function (User $user, int $noteId) {
    $note = Note::find($noteId);

    if ($note === null) {
        return false;
    }

    return $user->can('view', $note);
});
```

When the note is created (first step batch or first full-document save), the
client subscribes using the note's ID from the Inertia props. If the page loads
with no note yet, the client defers subscription until after the first save
returns a note ID.

### 3.7 Catch-Up Endpoint

When a client reconnects (e.g., tab was backgrounded, WebSocket dropped), it
needs to catch up from its last known version.

```
GET /note/steps?since={version}
```

**Response:**

```json
{
    "version": 45,
    "steps": [ ... ],
    "clientIDs": [ ... ]
}
```

The server reads from the Redis sorted set: `ZRANGEBYSCORE` with score >
`{version}`. If the requested version is older than what Redis has (i.e., the
steps have expired), return a 410 Gone response. The client must then do a full
document reload via a normal Inertia page visit.

---

## 4. Client-Side Architecture

### 4.1 New Dependencies

```
bun add prosemirror-collab @laravel/echo-react pusher-js
```

`pusher-js` is required by Echo even when using Reverb (Reverb speaks the Pusher
protocol).

### 4.2 Echo Configuration

Create `resources/js/echo.ts`:

```typescript
import { configureEcho } from '@laravel/echo-react';

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

Import this file early in the app's entry point (`app.tsx`).

### 4.3 Editor Integration

The collab plugin is added to the ProseMirror plugin array in `buildPlugins()`.
The initial version comes from the server (passed as an Inertia prop on the
note).

```typescript
import { collab, sendableSteps, receiveTransaction, getVersion } from 'prosemirror-collab';

// In buildPlugins():
collab({ version: initialVersion })
```

### 4.4 Sending Steps

Replace the current `handleUpdate` / `flushSave` debounced-fetch mechanism with
a collab-aware send loop:

```typescript
function sendSteps(view: EditorView) {
    const sendable = sendableSteps(view.state);
    if (!sendable) return;

    fetch('/note/steps', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getXsrfToken(),
        },
        body: JSON.stringify({
            version: sendable.version,
            steps: sendable.steps.map(s => s.toJSON()),
            clientID: sendable.clientID,
            doc: view.state.doc.toJSON(),
        }),
    })
    .then(res => {
        if (res.status === 409) {
            return res.json().then(data => {
                // Apply missing steps and retry
                applyReceivedSteps(view, data);
                sendSteps(view); // Retry after rebase
            });
        }
    });
}
```

This function is called from `dispatchTransaction` whenever `docChanged` is true.
A lightweight debounce (~100ms) or microtask batching is appropriate to coalesce
rapid keystrokes into fewer HTTP requests. The debounce should be shorter than
the current 500ms since we are no longer sending the full document on every
keystroke.

### 4.5 Receiving Steps

Use the `useEcho` hook to subscribe to the note's private channel:

```typescript
import { useEcho } from '@laravel/echo-react';

useEcho(
    `note.${noteId}`,
    '.steps.accepted',
    (event) => {
        applyReceivedSteps(view, event);
    }
);
```

The `applyReceivedSteps` function:

```typescript
import { receiveTransaction } from 'prosemirror-collab';
import { Step } from 'prosemirror-transform';

function applyReceivedSteps(
    view: EditorView,
    data: { version: number; steps: any[]; clientIDs: (string | number)[] }
) {
    const steps = data.steps.map(s => Step.fromJSON(schema, s));
    const tr = receiveTransaction(view.state, steps, data.clientIDs);
    view.dispatch(tr);
}
```

The collab plugin handles deduplication: if the client receives confirmation of
its own steps (matched by clientID), it marks those local steps as confirmed
rather than applying them again.

### 4.6 Client ID

Each tab generates a unique client ID on mount (e.g., `crypto.randomUUID()`).
This is passed to the `collab` plugin and sent with every step batch. It is used
by `receiveTransaction` to distinguish "my steps being confirmed" from "someone
else's steps I need to apply."

### 4.7 Reconnection

When the WebSocket reconnects (Pusher client handles this automatically), the
client should catch up:

1. Call `GET /note/steps?since={getVersion(view.state)}`.
2. If 200, apply the returned steps via `receiveTransaction`.
3. If 410 (steps expired from Redis), do a full page reload to get the latest
   document from the server.
4. After catching up, check for any unsent local steps and send them.

Listen for the Pusher `connected` event (or Echo's reconnection callback) to
trigger this.

### 4.8 Removing the Old Save Mechanism

The current flow in `Home.tsx` -- 500ms debounced `fetch('PUT /note')` sending
the full document -- is replaced entirely by the collab step-sending mechanism.
The `PUT /note` endpoint, `NoteUpdateController`, and `NoteUpdateData` have been
removed. `POST /note/steps` is the only write path.

There is no fallback to a full-document PUT save. If Reverb is unavailable, the
collab system still functions because step submission is HTTP-based (`POST
/note/steps`). Reverb is only used for broadcasting accepted steps to other tabs.
The `NoteStepsController` already wraps broadcasts in a try/catch so Reverb
downtime is non-fatal -- the submitting client's document is persisted normally,
and other tabs catch up via the `GET /note/steps` polling endpoint when they
reconnect.

The legacy save code (`flushSave`, `handleUpdateLegacy`, and associated refs)
has been removed from `Home.tsx`.

---

## 5. Sequence Diagrams

### Normal editing flow

```
Tab A                    Server                    Tab B
  |                        |                        |
  |-- POST /note/steps --->|                        |
  |   {version:5, steps}   |                        |
  |                        |-- validate, apply      |
  |                        |-- store in Redis       |
  |                        |-- save doc to DB       |
  |<-- 200 {version:7} ---|                        |
  |                        |                        |
  |                        |-- broadcast via Reverb |
  |                        |-- steps.accepted ------>|
  |                        |                        |
  |                        |                        |-- receiveTransaction()
  |                        |                        |-- (rebases any local steps)
```

### Conflict / stale version

```
Tab A                    Server                    Tab B
  |                        |                        |
  |                        |<-- POST /note/steps ---|
  |                        |    {version:5, steps}  |
  |                        |-- accept, version -> 7 |
  |                        |                        |
  |-- POST /note/steps --->|                        |
  |   {version:5, steps}   |                        |
  |                        |-- version mismatch!    |
  |<-- 409 {version:7,  ---|                        |
  |    steps, clientIDs}   |                        |
  |                        |                        |
  |-- receiveTransaction() |                        |
  |-- (rebases local steps)|                        |
  |-- POST /note/steps --->|                        |
  |   {version:7, steps}   |                        |
  |                        |-- accept               |
```

---

## 6. Background Job Debouncing

The step acceptance endpoint dispatches `GenerateWeeklySummary` and
`GenerateNoteEmbeddings` after persisting each step batch. Without debouncing,
typing a paragraph would trigger these expensive AI jobs on every keystroke
batch (~every 100ms of typing).

### Mechanism

Both jobs implement `ShouldBeUnique` with a `uniqueFor` TTL (configurable,
defaults to 3600 seconds). This means:

1. The first step batch dispatches the job with a **30-second delay**.
2. The unique lock is acquired at dispatch time.
3. Subsequent dispatches within the `uniqueFor` window are silently dropped by
   Laravel's queue system.
4. After the 30-second delay, the queue worker executes the job against the
   current database state, which by then contains all the content the user typed
   during those 30 seconds.
5. Once the job finishes (and the unique lock is released or expires), the next
   step batch can dispatch a fresh job.

The 30-second delay is the key: it ensures that rapid typing triggers the job
only once, and that job runs against a document that has at least 30 seconds of
edits baked in. This is sufficient for a single-user journaling app where the
user types continuously for minutes at a time.

### Configuration

The debounce window (delay) and unique-for TTL are separate concerns:

- **Delay** (30 seconds): How long to wait before running the job after the
  first step batch in a burst. This is the "paragraph buffer."
- **uniqueFor** (config-driven, default 3600 seconds): How long the unique lock
  is held. This acts as a rate limit -- even if the user types for an hour
  straight, the job runs at most once per `uniqueFor` period.

Both values can be tuned independently. The delay is set in the controller; the
unique-for TTL is set on the job classes via config (`summaries.debounce_seconds`
and `embeddings.debounce_seconds`).

---

## 7. Installation & Configuration

### Packages

```bash
# PHP
composer require laravel/reverb

# JS
bun add prosemirror-collab @laravel/echo-react pusher-js
```

### Reverb setup

```bash
php artisan install:broadcasting --reverb
```

This creates `config/broadcasting.php`, `config/reverb.php`,
`routes/channels.php`, and adds the necessary `.env` variables.

### Environment variables

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=daybook
REVERB_APP_KEY=daybook-key
REVERB_APP_SECRET=daybook-secret
REVERB_HOST="localhost"
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Redis

Redis is already configured in `.env` and `config/database.php`. No changes
needed. The step log uses the default Redis connection.

### Running

Add `php artisan reverb:start` to the `composer run dev` script (alongside the
existing Vite dev server and Laravel server).

---

## 8. Implementation Plan

### Phase 1: Infrastructure

1. Install Reverb and Echo packages.
2. Run `php artisan install:broadcasting --reverb`.
3. Add the `version` column migration to the `notes` table.
4. Configure `.env` and Vite env variables.
5. Set up the channel authorization route.
6. Verify Reverb starts and a client can subscribe to a private channel.

### Phase 2: Server-Side Step Authority

1. Create `NoteStepsAccepted` broadcast event.
2. Create `POST /note/steps` endpoint with locking, validation, Redis storage,
   DB persistence, and broadcasting.
3. Create `GET /note/steps?since=` catch-up endpoint.
4. Add `version` to the Inertia props returned by `NoteShowController`.
5. Remove `PUT /note` route, `NoteUpdateController`, and `NoteUpdateData`.
6. Dispatch `GenerateWeeklySummary` and `GenerateNoteEmbeddings` from the step
   endpoint with a 30-second delay (debounced via `ShouldBeUnique`).
7. Persist the user's timezone from the step endpoint for background jobs.
8. Write Pest tests for the step acceptance endpoint (version mismatch, locking,
   Redis storage, broadcast assertion).

### Phase 3: Client-Side Collab

1. Add `prosemirror-collab` plugin to `buildPlugins()`.
2. Configure Echo in `resources/js/echo.ts` and import in `app.tsx`.
3. Replace the debounced full-document save with the collab step-sending loop.
4. Remove the legacy `PUT /note` save code (`flushSave`, `handleUpdateLegacy`,
   and associated refs) from `Home.tsx`.
5. Wire up `useEcho` to receive steps and dispatch `receiveTransaction`.
6. Generate a per-tab `clientID` on mount.
7. Handle 409 conflict responses (apply missing steps, retry).
8. Handle reconnection (catch-up via `GET /note/steps`).

### Phase 4: Edge Cases & Polish

1. Handle Redis step expiry (410 -> full reload).
2. Handle note creation race (two tabs open on a day with no note yet -- both
   try to create it).
3. Verify background job debouncing works correctly (jobs fire once after typing
   stops, not on every step batch).

---

## 9. Testing Strategy

### Unit tests (Pest)

- **Step acceptance:** Submit steps at the correct version, assert DB document
  updated, assert Redis sorted set contains steps, assert broadcast event fired.
- **Version conflict:** Submit steps at a stale version, assert 409 with missing
  steps in response.
- **Locking:** Simulate concurrent step submissions, assert serial execution
  (no lost updates).
- **Catch-up endpoint:** Seed Redis with steps, request with `since` param,
  assert correct subset returned.
- **Step expiry:** Request catch-up with a version older than Redis has, assert
  410.
- **Channel authorization:** Assert that `NotePolicy@view` is enforced on the
  channel -- only the note's owner can subscribe. Assert that a different user
  is rejected. Assert that a nonexistent note ID is rejected.

### Integration / browser tests (optional, Dusk)

- Open two browser tabs, type in one, verify the other updates.
- Kill Reverb mid-edit, verify the client recovers on reconnection.

### Manual testing

- Open the same note in two tabs. Type in one, watch the other update in
  real-time. Type in both simultaneously and verify no content is lost.
