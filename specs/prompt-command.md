# Prompt Command

## Overview

Replace the "Search..." text input in the floating menu with a larger `<textarea>`
labeled "Prompt...". When the user presses Enter (without Shift or Option), the prompt
text is submitted to Laravel, which shells out to `opencode run "{prompt}"` in the
background from the project root. Shift+Enter and Option+Enter insert a newline as
usual.

The search functionality is removed. The textarea serves as a command prompt for
dispatching AI coding tasks via OpenCode.

---

## 1. Frontend Changes (`FloatingMenu.tsx`)

### Replace `<input>` with `<textarea>`

Swap the single-line `<input type="text">` for a `<textarea>` with:

- `placeholder="Prompt..."`
- 3 rows tall by default (`rows={3}`)
- Same styling approach as the current input (transparent background, matching text
  and placeholder colors) but with `resize-none` to prevent manual resizing
- The textarea should grow naturally within the sticky-note container

### Key Handling

The `handleKeyDown` function changes behavior:

- **Enter** (no modifier): prevent default, submit the prompt, clear the textarea
- **Shift+Enter** / **Option+Enter (Alt+Enter)**: allow default behavior (insert newline)
- **Escape**: clear the textarea and blur, same as current behavior

```tsx
function handleKeyDown(e: React.KeyboardEvent<HTMLTextAreaElement>) {
    if (e.key === 'Enter' && !e.shiftKey && !e.altKey) {
        e.preventDefault();
        submitPrompt(query);
    }
    if (e.key === 'Escape') {
        setQuery('');
        (e.target as HTMLTextAreaElement).blur();
    }
}
```

### Submit via Inertia

On submit, POST to `/prompt` using Inertia's router. This is a fire-and-forget action
from the user's perspective -- no response data is needed. The textarea clears
immediately after submission.

```tsx
function submitPrompt(text: string) {
    const trimmed = text.trim();
    if (trimmed === '') return;

    router.post('/prompt', { prompt: trimmed }, {
        preserveState: true,
        preserveScroll: true,
    });

    setQuery('');
}
```

### Remove Search-Related Code

All search-specific state and logic is removed:

- `results`, `loading`, `searched` state variables
- `debounceRef`, `abortRef` refs
- The `search()` callback and `handleInputChange` debounce logic
- The search results popup and "No matches found" popup
- The `SearchResult` interface
- The `getCsrfToken()` and `formatResultDate()` helper functions
- The `onNavigate` prop (only used for search result navigation)

The `onChange` handler becomes a simple setter:

```tsx
function handleChange(e: React.ChangeEvent<HTMLTextAreaElement>) {
    setQuery(e.target.value);
}
```

### Updated Component Structure

The simplified floating menu contains only the textarea, a loading/submitted indicator
(optional), and the "..." menu button.

```tsx
<div className="rotate-1 bg-gray-100 px-5 py-3 shadow-[2px_3px_12px_rgba(0,0,0,0.12)] dark:bg-[#131113] dark:shadow-[2px_3px_16px_rgba(0,0,0,0.5)]">
    <div className="flex items-start gap-4">
        <textarea
            rows={3}
            placeholder="Prompt..."
            value={query}
            onChange={handleChange}
            onKeyDown={handleKeyDown}
            className="w-64 resize-none bg-transparent text-gray-700 placeholder-gray-400 outline-none dark:text-gray-200 dark:placeholder-gray-500"
        />
        <button
            onClick={() => setMenuOpen(!menuOpen)}
            className="..."
            aria-label="Menu"
        >
            ...
        </button>
    </div>
</div>
```

Note: `items-center` becomes `items-start` since the textarea is multi-line and the
menu button should align to the top.

---

## 2. Route

Add a new POST route in `routes/web.php` inside the existing `auth` middleware group:

```php
Route::post('/prompt', PromptController::class);
```

---

## 3. Controller (`app/Http/Controllers/PromptController.php`)

A single-action invokable controller that validates the prompt and dispatches a
background job.

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\RunPrompt;
use Illuminate\Http\Request;

class PromptController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:5000'],
        ]);

        RunPrompt::dispatch($validated['prompt']);

        return back();
    }
}
```

The controller:

1. Validates the prompt (required, string, max 5000 characters)
2. Dispatches a queued job to run the command in the background
3. Returns `back()` -- Inertia handles this as a redirect back to the current page

The prompt is not tied to a user or persisted in the database for now. It is a
fire-and-forget command.

---

## 4. Background Job (`app/Jobs/RunPrompt.php`)

The job shells out to `opencode run "{prompt}"` using Symfony's `Process` component.
This runs on the queue so the HTTP request returns immediately.

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Process\Process;

class RunPrompt implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public string $prompt,
    ) {}

    public function handle(): void
    {
        $process = new Process(
            command: ['opencode', 'run', $this->prompt],
            cwd: base_path(),
            timeout: 300,
        );

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'opencode run failed: ' . $process->getErrorOutput()
            );
        }
    }
}
```

### Key Decisions

- **`Process` with array syntax**: The command is passed as an array
  (`['opencode', 'run', $this->prompt]`) rather than a string. Symfony's Process
  component automatically escapes each argument when using array syntax, preventing
  shell injection. The prompt is never interpolated into a shell string.
- **Working directory**: `base_path()` sets the cwd to the project root, so OpenCode
  runs in the correct context.
- **Timeout**: 5 minutes (300 seconds). OpenCode tasks can take time. Both the job
  `$timeout` and the Process `timeout` are set to match.
- **No retries**: `$tries = 1`. If the command fails, it lands in `failed_jobs`. The
  user can re-submit from the UI.
- **Failure handling**: If the process exits non-zero, the job throws a
  `RuntimeException` with the stderr output, which Laravel logs and records in
  `failed_jobs`.

### Symfony Process Component

`symfony/process` (v7.4.5) is already installed as a transitive dependency of Laravel.
No additional packages need to be added.

---

## 5. Removing the Search Route

The `GET /search` route and `SearchController` can be removed since the search
functionality is being replaced. This is a clean removal:

- Delete `Route::get('/search', SearchController::class)->name('search');` from
  `routes/web.php`
- Delete `app/Http/Controllers/SearchController.php`

The `NoteEmbedding` model and embedding infrastructure can remain -- they may be useful
for future features.

---

## 6. Implementation Plan

1. Create `app/Jobs/RunPrompt.php` -- the queued job that shells out to OpenCode
2. Create `app/Http/Controllers/PromptController.php` -- validates and dispatches
3. Add `POST /prompt` route to `routes/web.php`
4. Update `FloatingMenu.tsx` -- replace `<input>` with `<textarea>`, remove search
   logic, add prompt submission via Inertia
5. Remove `GET /search` route and `SearchController`
6. Write tests

---

## 7. Testing Strategy

### Feature Tests

- **`PromptController`**: POST `/prompt` with a valid prompt dispatches `RunPrompt`
  (use `Queue::fake()`). Verify the job receives the correct prompt string.
- **Validation**: POST `/prompt` with an empty string returns a validation error. POST
  with a string over 5000 characters returns a validation error.
- **Authentication**: POST `/prompt` as a guest returns a redirect to login.

### Unit Tests

- **`RunPrompt` job**: Mock `Process` or use a process spy to verify the command
  is constructed correctly (`['opencode', 'run', $prompt]`), the cwd is `base_path()`,
  and the timeout is 300 seconds.
- **`RunPrompt` failure**: Verify the job throws `RuntimeException` when the process
  exits non-zero, and that the error output is included in the exception message.

### Browser Tests

- Verify the floating menu shows a textarea with "Prompt..." placeholder instead of
  the old search input
- Verify pressing Enter submits a POST to `/prompt` and clears the textarea
- Verify pressing Shift+Enter inserts a newline without submitting
- Verify pressing Escape clears the textarea

---

## 8. Open Questions

1. **Feedback to the user** -- currently this is fire-and-forget. Should there be any
   indication that the prompt was received (a brief flash, a toast, a checkmark)? For
   v1, the textarea clearing on submit serves as implicit acknowledgment.
2. **Prompt history** -- should submitted prompts be stored in a `prompts` table so
   the user can see what they've asked? Not needed for v1 but worth considering.
3. **Output capture** -- `opencode run` may produce output. Should we capture and
   display it? For v1, the output is discarded. Future iterations could stream it back
   via WebSockets (Reverb is already installed).
4. **Queue worker** -- this assumes a queue worker is running to process the job. If
   the app uses `sync` queue driver in development, the HTTP request will block until
   `opencode run` completes (up to 5 minutes). Document that an async queue driver
   (database, Redis) is recommended.
