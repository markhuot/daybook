<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateWeeklySummary;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NoteUpdateController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'content' => ['nullable', 'array'],
        ]);

        $timezone = $request->cookie('timezone', 'UTC');
        $today = Carbon::now($timezone)->toDateString();

        $content = $validated['content'];

        // Treat empty documents (no real text) the same as null
        if ($content !== null && $this->isDocEmpty($content)) {
            $content = null;
        }

        $user = $request->user();
        $note = $user->notes()->where('date', $today)->first();

        if ($content === null) {
            // Nothing to store — delete the row if it exists
            $note?->delete();
        } else {
            // Upsert: create or update today's note
            if ($note) {
                $note->update(['content' => $content]);
            } else {
                $user->notes()->create([
                    'date' => $today,
                    'content' => $content,
                ]);
            }
        }

        // Persist timezone for background jobs
        $user->update(['timezone' => $timezone]);

        // Dispatch summary generation (debounced via ShouldBeUnique + uniqueFor on the job)
        GenerateWeeklySummary::dispatch($user);

        return back();
    }

    /**
     * Check whether a ProseMirror doc JSON has no meaningful text content.
     */
    private function isDocEmpty(array $doc): bool
    {
        return trim($this->extractText($doc)) === '';
    }

    private function extractText(array $node): string
    {
        $text = '';

        if (isset($node['text'])) {
            $text .= $node['text'];
        }

        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                $text .= $this->extractText($child);
            }
        }

        return $text;
    }
}
