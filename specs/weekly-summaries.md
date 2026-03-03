# Summaries

## Overview

Users see AI-generated summaries displayed below the editor when viewing today's note.
Summaries are non-editable, regenerate periodically as the user writes, and are
generated entirely by a local LLM -- no data leaves the machine.

There are two summaries, both stored as columns on the `users` table:

- **Weekly summary** -- a rolling look-back over the past 7 days of notes
- **Monthly summary** -- a rolling look-back over the past 30 days of notes

Both summaries include a recap of what the user did and gentle suggestions for goals
or tasks that could benefit their productivity, mental health, or overall wellbeing.

Summaries are only displayed on today's view. When viewing past dates, no summary is
shown -- the summaries reflect the present rolling window and would be misleading on
historical dates.

Each summary has a **staleness TTL**. If a summary hasn't been regenerated in over 24
hours, it is treated as stale and not displayed. This prevents showing outdated
information when Ollama has been unavailable.

---

## 1. Local LLM Runtime

### Ollama

Use [Ollama](https://ollama.com) as the local inference server. It exposes an
OpenAI-compatible HTTP API at `http://localhost:11434`, handles model downloading and
management, and runs on macOS/Linux/Windows.

**Recommended model:** `qwen3.5:4b` (3.4 GB, 256K context window). This is the sweet
spot for local summarization -- small enough to run on any modern laptop, large enough
to produce coherent prose. The 0.8B variant (1 GB) is available as a fallback for
constrained hardware.

### Installation & Setup

Ollama is a user-installed prerequisite, not bundled with Daybook. The app should:

1. **Detect Ollama availability** -- the `Ollama` service checks connectivity before
   each LLM call via `GET http://localhost:11434/api/tags`.
2. **Fail loudly in jobs** -- if Ollama is unreachable or the model is missing, the
   queue job throws an exception and fails. Laravel's built-in retry/failed job
   handling takes care of the rest. The job will appear in the `failed_jobs` table for
   debugging.
3. **Degrade silently in the UI** -- the web layer never mentions summaries if they
   are `null`. No install prompts, no empty states. The feature is invisible until it
   has data to show.
4. **Configuration** -- add these to `.env` with sensible defaults:
   ```
   OLLAMA_HOST=http://localhost:11434
   OLLAMA_MODEL=qwen3.5:4b
   SUMMARY_DEBOUNCE_SECONDS=3600
   SUMMARY_TTL_SECONDS=86400
   ```
   Expose via `config/services.php` under an `ollama` key. The debounce interval
   controls how frequently summaries can regenerate per user (default: 3600 seconds /
   1 hour). The TTL controls how long a summary is considered fresh (default: 86400
   seconds / 24 hours).

### PHP Integration

Use Laravel's built-in `Http` client to call the Ollama API directly. No additional
PHP packages are needed. The relevant endpoint is:

```
POST /api/chat
{
  "model": "qwen3.5:4b",
  "messages": [
    {"role": "system", "content": "..."},
    {"role": "user", "content": "..."}
  ],
  "stream": false
}
```

Setting `stream: false` returns the complete response in a single JSON payload, which
is simpler for background job processing.

### Service Class

Create `app/Services/Ollama.php` -- a thin wrapper around the HTTP calls:

```php
class Ollama
{
    public function isAvailable(): bool;
    public function chat(string $system, string $user): string;
}
```

`chat()` returns a string on success and throws an exception on failure (connection
refused, model not found, empty response, etc.). The calling job does not catch this
-- it lets the exception propagate so the job fails visibly.

This keeps the Ollama interaction isolated and testable. In tests, bind a fake
implementation that returns canned responses.

---

## 2. Data Model

### Migration: Add Summary and Timezone Columns to Users

No new table needed. Add five nullable columns to the existing `users` table:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('timezone')->nullable(); // e.g. "America/New_York"
    $table->text('weekly_summary')->nullable();
    $table->timestamp('weekly_summary_at')->nullable();
    $table->text('monthly_summary')->nullable();
    $table->timestamp('monthly_summary_at')->nullable();
});
```

- `timezone` -- the user's IANA timezone string (e.g. `America/New_York`). Persisted
  from the `timezone` cookie whenever the user saves a note (see section 3). This
  allows background jobs to determine "today" for the user without access to the
  request cookie.
- `weekly_summary` / `monthly_summary` -- the LLM-generated markdown text, overwritten
  on each regeneration.
- `weekly_summary_at` / `monthly_summary_at` -- when the summary was last generated.
  Used to enforce the 24-hour staleness TTL. If `now() - weekly_summary_at > TTL`, the
  summary is treated as `null` in the controller.

The `User` model needs `casts` for the timestamp columns:

```php
protected function casts(): array
{
    return [
        // ...existing casts...
        'weekly_summary_at' => 'datetime',
        'monthly_summary_at' => 'datetime',
    ];
}
```

There is no history of past summaries -- each regeneration replaces the previous value.

---

## 3. Triggering Summary Generation

### On Note Save or Delete (Debounced)

When `NoteUpdateController` saves or deletes a note, dispatch both summary jobs for
that user. The controller should also persist the user's timezone from the cookie at
this point:

```php
// In NoteUpdateController, after saving or deleting:

// Persist timezone for background jobs
$user->update(['timezone' => $request->cookie('timezone', 'UTC')]);

$debounce = config('services.ollama.debounce_seconds', 3600);

GenerateWeeklySummary::dispatch($user)
    ->uniqueFor(seconds: $debounce);

GenerateMonthlySummary::dispatch($user)
    ->uniqueFor(seconds: $debounce);
```

Jobs are dispatched on **both** create/update and delete paths. Deleting a note (when
the user clears all content) changes the rolling window input, so the summary should
reflect that.

Since autosave fires frequently (every 500ms of inactivity) and summaries are
expensive to generate, each job is **debounced to once per hour** by default.
Laravel's `ShouldBeUnique` interface with `uniqueFor` prevents duplicate jobs. If a
job for the same user is already pending or was recently completed within the debounce
window, the new dispatch is silently dropped.

The debounce interval is read from `config('services.ollama.debounce_seconds')` so it
can be tuned per environment. In development you might set it to 60 seconds; in
production, 3600 is a reasonable default.

### Scheduled Catch-Up (Phase 2)

A daily scheduled command regenerates summaries for all active users. This handles
edge cases like Ollama being down when the user was writing, or the rolling window
shifting overnight without any new saves.

```php
// routes/console.php
Schedule::command('summaries:generate')->dailyAt('03:00');
```

For the initial implementation, the on-save trigger alone is sufficient.

---

## 4. The Generation Jobs

Two separate jobs run independently on the queue. This prevents a slow monthly
generation from blocking the weekly one, and allows them to be debounced or scheduled
differently in the future.

**No retries.** If Ollama is unreachable and the job throws, it fails once and lands in
`failed_jobs`. The next note save (after the debounce window expires) will dispatch a
fresh job. This keeps things simple and avoids retry storms when Ollama is down.

```php
public int $tries = 1;
```

### `app/Jobs/GenerateWeeklySummary.php`

```php
class GenerateWeeklySummary implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor;
    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(
        public User $user,
    ) {
        $this->uniqueFor = config('services.ollama.debounce_seconds', 3600);
    }

    public function uniqueId(): string
    {
        return 'weekly:' . $this->user->id;
    }

    public function handle(Ollama $ollama): void
    {
        $today = now($this->user->timezone ?? 'UTC')->toDateString();

        $notes = $this->user->notes()
            ->whereBetween('date', [
                Carbon::parse($today)->subDays(6)->toDateString(),
                $today,
            ])
            ->whereNotNull('content')
            ->orderBy('date')
            ->get();

        if ($notes->isEmpty()) {
            $this->user->update([
                'weekly_summary' => null,
                'weekly_summary_at' => null,
            ]);
            return;
        }

        $notesText = $this->formatNotes($notes);
        $summary = $ollama->chat(
            system: $this->systemPrompt(),
            user: $notesText,
        );

        $this->user->update([
            'weekly_summary' => $summary,
            'weekly_summary_at' => now(),
        ]);
    }
}
```

### `app/Jobs/GenerateMonthlySummary.php`

Same structure, but queries the past 30 days and uses the monthly system prompt. The
`uniqueId` returns `'monthly:' . $this->user->id` to allow independent debouncing
from the weekly job.

The monthly job sends all 30 days in a single pass to the LLM. The 256K context
window on `qwen3.5:4b` is more than sufficient for a month of journal entries.

### Text Extraction

Notes are stored as ProseMirror JSON. Both jobs share a `extractText(Note $note):
string` helper (in a trait or base class) that recursively walks the JSON tree and
concatenates all `text` nodes. This gives the LLM clean input without markup structure.

The `formatNotes` method prepends each note's extracted text with its date:

```
## Tuesday, February 25

Called Sarah about the API redesign. She wants to move forward with GraphQL...

## Wednesday, February 26

Finished the migration script. Ran into issues with nullable columns...
```

### System Prompts

The LLM is instructed to output **markdown**. Summaries are stored as raw markdown in
the database and converted to HTML server-side via `Str::markdown()` before being
passed to the frontend (see section 5).

The prompts should be iterated on over time. Initial versions:

**Weekly (past 7 days):**

```
You are a thoughtful assistant for a personal daily journal app. The user
will provide their journal entries from the past 7 days.

Write a response in markdown with two sections:

1. A brief 2-3 sentence summary of their week — what they worked on, key
   themes, and noteworthy events. Write in second person ("You..."). Be
   specific and reference actual things they mentioned.

2. Under a "## Suggested goals" heading, offer 2-3 gentle, actionable
   suggestions for the coming days. These can relate to productivity,
   mental health, physical health, or personal wellbeing. Base them on
   what the user actually wrote — if they mentioned feeling stressed,
   suggest rest; if they completed a big project, suggest celebration or
   a break. Do not be preachy or generic. Use a markdown bullet list.

Keep the total response under 150 words.
```

**Monthly (past 30 days):**

```
You are a thoughtful assistant for a personal daily journal app. The user
will provide their journal entries from the past 30 days.

Write a response in markdown with two sections:

1. A 3-5 sentence summary of their month — recurring themes, major
   accomplishments, patterns in how they spent their time, and any shifts
   in focus or mood over the month. Write in second person ("You..."). Be
   specific and reference actual things they mentioned.

2. Under a "## Suggested goals" heading, offer 2-4 actionable suggestions
   for the coming weeks. These should reflect longer-term patterns — if
   they've been consistently overworked, suggest boundaries; if they
   started a new habit, encourage continuing it. Suggestions can cover
   productivity, mental health, physical health, relationships, or
   personal growth. Ground every suggestion in something from their notes.
   Use a markdown bullet list.

Keep the total response under 250 words.
```

These prompts will need iteration once we can test with real note data and see what
the model produces. The tone should feel like a supportive friend, not a therapist or
life coach.

---

## 5. Serving Summaries to the Frontend

### Controller Changes (`NoteShowController`)

Only include summaries when viewing today's note, and only if they are fresh (within
the configured TTL). The controller converts markdown to HTML before passing it to the
frontend:

```php
use Illuminate\Support\Str;

$isToday = $date === $today;
$ttl = config('services.ollama.ttl_seconds', 86400);

$weeklySummary = null;
$monthlySummary = null;

if ($isToday) {
    if ($user->weekly_summary_at && $user->weekly_summary_at->diffInSeconds(now()) < $ttl) {
        $weeklySummary = Str::markdown($user->weekly_summary);
    }
    if ($user->monthly_summary_at && $user->monthly_summary_at->diffInSeconds(now()) < $ttl) {
        $monthlySummary = Str::markdown($user->monthly_summary);
    }
}

return Inertia::render('Home', [
    'note' => $note,
    'notes' => $notes,
    'previousContent' => $previousContent ?? null,
    'weeklySummary' => $weeklySummary,
    'monthlySummary' => $monthlySummary,
]);
```

**Markdown to HTML:** Uses Laravel's built-in `Str::markdown()`, which wraps
`league/commonmark` (already a dependency of `laravel/framework`). No additional
packages needed. The conversion uses the GithubFlavoredMarkdownConverter by default,
which handles headings, bold, italic, bullet lists, and more. The `html_input` option
is set to `strip` by default, which provides basic sanitization.

The database stores raw markdown. HTML is derived at render time. This keeps the
stored data format-agnostic and allows re-rendering if the markdown library or styling
changes.

### Frontend Changes (`Home.tsx`)

Add to the `Props` interface:

```typescript
interface Props {
    note: Note;
    notes: Record<string, NoteEntry>;
    previousContent?: Record<string, unknown> | null;
    weeklySummary?: string | null;
    monthlySummary?: string | null;
}
```

The summary props now arrive as **HTML strings** (already converted server-side).

### Rendering as HTML in Sticky Notes

Summaries are rendered as HTML inside sticky-note containers that match the app's
existing visual language.

The app already uses a consistent sticky-note metaphor (slight rotation, soft shadow,
`bg-gray-100`, optional tape strip) on the floating menu, login page, and registration
page. The summary cards follow this same pattern.

```tsx
{(weeklySummary || monthlySummary) && (
    <aside className="mt-12 flex flex-col items-center gap-8">
        {weeklySummary && (
            <div className="max-w-md rotate-1 bg-gray-100 px-6 py-5
                            shadow-[2px_3px_12px_rgba(0,0,0,0.12)]
                            dark:bg-[#131113] dark:shadow-[2px_3px_16px_rgba(0,0,0,0.4)]">
                <h2 className="mb-2 text-sm font-semibold text-gray-700
                               dark:text-gray-300">This week</h2>
                <div
                    className="prose prose-sm prose-gray dark:prose-invert"
                    dangerouslySetInnerHTML={{ __html: weeklySummary }}
                />
            </div>
        )}
        {monthlySummary && (
            <div className="max-w-md -rotate-1 bg-gray-100 px-6 py-5
                            shadow-[2px_3px_12px_rgba(0,0,0,0.12)]
                            dark:bg-[#131113] dark:shadow-[2px_3px_16px_rgba(0,0,0,0.4)]">
                <h2 className="mb-2 text-sm font-semibold text-gray-700
                               dark:text-gray-300">This month</h2>
                <div
                    className="prose prose-sm prose-gray dark:prose-invert"
                    dangerouslySetInnerHTML={{ __html: monthlySummary }}
                />
            </div>
        )}
    </aside>
)}
```

**Tailwind Typography plugin:** The `prose` classes above come from
`@tailwindcss/typography` (`bun add @tailwindcss/typography`). This gives the rendered
HTML proper typographic styling (paragraph spacing, list bullets, bold/italic, heading
sizes) with minimal effort. If the plugin is too heavy for this use case, the
alternative is a small set of custom CSS rules targeting the summary container's child
elements.

### Client-Side Cache Consideration

Since summaries only appear on today's view, and today's view is always fetched fresh
from the server (never served from the client-side note cache), no special cache
handling is needed. When the user navigates away from today and back, Inertia will
re-fetch the page and include the latest summaries.

---

## 6. Implementation Plan

### Phase 1: Foundation

1. Add `OLLAMA_HOST`, `OLLAMA_MODEL`, `SUMMARY_DEBOUNCE_SECONDS`, and
   `SUMMARY_TTL_SECONDS` to `.env` and `config/services.php`
2. Create `app/Services/Ollama.php` service class (throws on failure)
3. Migration: add `timezone`, `weekly_summary`, `weekly_summary_at`,
   `monthly_summary`, and `monthly_summary_at` columns to `users` table
4. Update `User` model with casts for the new timestamp columns
5. Create `GenerateWeeklySummary` and `GenerateMonthlySummary` jobs (`tries: 1`,
   text extraction, LLM calls)
6. Update `NoteUpdateController` to persist timezone and dispatch both jobs on
   save and delete, with configurable debounce
7. Pass summary props (as HTML via `Str::markdown()`) from `NoteShowController`
   (today only, with TTL check)
8. Add `@tailwindcss/typography` package (the only new dependency --
   `league/commonmark` already ships with Laravel)
9. Render the summaries as HTML inside sticky-note containers in `Home.tsx`

### Phase 2: Polish

10. Add a scheduled `summaries:generate` command for daily catch-up
11. Prompt tuning based on real note data and model output quality
12. Fine-tune sticky note styling, rotation angles, and responsive behavior

### Phase 3: Future Considerations

- **Streaming** -- if Ollama latency becomes an issue, stream the response via SSE and
  display it as it generates (significant frontend complexity)
- **Model selection UI** -- let users pick their preferred Ollama model in settings
- **Summary quality feedback** -- thumbs up/down to help tune the prompt over time
- **Structured extraction** -- use structured output to pull out completed tasks,
  projects mentioned, mood trends, etc. for richer summaries
- **Summary history** -- if users want to look back at what their summary said last
  week, store snapshots in a separate table

---

## 7. Testing Strategy

### Unit Tests

- `Ollama` service: mock HTTP responses, verify `isAvailable()` and `chat()` behavior
  for success, timeout, and Ollama-down scenarios. Verify that `chat()` throws on
  failure.
- `GenerateWeeklySummary` job: fake the Ollama service, verify it loads the correct
  date range (past 7 days), builds the right prompt, and writes the summary + timestamp
  to the user model. Verify it throws when Ollama is unavailable.
- `GenerateMonthlySummary` job: same, but for the past 30 days.
- Text extraction: test with various ProseMirror JSON structures (paragraphs, lists,
  nested content, empty docs)

### Feature Tests

- `NoteUpdateController`: verify both jobs are dispatched (use `Queue::fake()`) when a
  note is saved, and also when a note is deleted (content cleared)
- `NoteUpdateController`: verify the user's timezone is persisted from the cookie
- `NoteShowController`: verify summary props are included as HTML when viewing today
  with fresh summaries, `null` when viewing a past date, and `null` when summaries are
  stale (older than TTL)
- Graceful degradation: verify no errors or UI breakage when summaries are null

### Browser Tests

- Verify the summary sections appear below the editor on today's view when summaries
  exist, rendered as HTML inside sticky-note containers
- Verify no summary sections render when viewing a past date
- Verify no summary sections render when the props are null
- Verify the summary content is not editable (no contenteditable, no form inputs)

---

## 8. Open Questions

1. **Monthly summary cost** -- 30 days of notes in a single pass could be slow on the
   4B model for prolific writers. Monitor inference times and consider truncation or a
   two-pass strategy if it becomes a problem.
2. **Summary history** -- once summaries are overwritten, they're gone. Is that
   acceptable for v1, or should we snapshot them for future reference?
3. **Sticky note placement** -- the spec puts both notes stacked vertically below the
   editor. An alternative is side-by-side (weekly left, monthly right) on wider
   viewports. Worth testing once the feature is live.
4. **Typography plugin weight** -- `@tailwindcss/typography` adds CSS. If bundle size
   is a concern, a handful of custom rules targeting the summary container's children
   would be lighter.
