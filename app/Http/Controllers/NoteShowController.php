<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class NoteShowController extends Controller
{
    public function __invoke(Request $request, ?string $date = null): Response|HttpResponse
    {
        $timezone = $request->cookie('timezone', 'UTC');
        $today = Carbon::now($timezone)->toDateString();

        $date = $date ?? $today;

        if ($date > $today) {
            abort(425, 'Too Early');
        }

        $note = $request->user()->notes()->where('date', $date)->first();

        // Build a 10-day window using adaptive windowing.
        // daysForward = min(4, days between viewed date and today)
        // daysBack = 9 - daysForward
        // This weights the window toward the past when viewing dates near today.
        $viewedDate = Carbon::parse($date);
        $todayCarbon = Carbon::parse($today);
        $daysForward = min(4, (int) abs($todayCarbon->diffInDays($viewedDate)));
        $daysBack = 9 - $daysForward;

        $windowStart = $viewedDate->copy()->subDays($daysBack)->toDateString();
        $windowEnd = min($viewedDate->copy()->addDays($daysForward)->toDateString(), $today);

        // Fetch all notes in the window
        $windowNotes = $request->user()->notes()
            ->whereBetween('date', [$windowStart, $windowEnd])
            ->get()
            ->keyBy('date');

        // Build the notes map: every date in window gets an entry
        $notes = [];
        $cursor = Carbon::parse($windowStart);
        $end = Carbon::parse($windowEnd);
        while ($cursor->lte($end)) {
            $d = $cursor->toDateString();
            $existingNote = $windowNotes->get($d);
            $notes[$d] = [
                'id' => $existingNote?->id,
                'content' => $existingNote?->content,
            ];
            $cursor->addDay();
        }

        $props = [
            'note' => [
                'id' => $note?->id,
                'date' => $date,
                'content' => $note?->content,
            ],
            'notes' => $notes,
        ];

        // If today's note has no content, offer a starting point.
        // Show the AI-generated placeholder only if it was created from the most
        // recent note (i.e., the AI has processed the latest entry). Otherwise
        // fall back to a static "What's on your plate today?" prompt.
        if ($date === $today && ! $note?->content) {
            $user = $request->user();

            $mostRecentNoteDate = $user->notes()
                ->where('date', '<', $date)
                ->whereNotNull('content')
                ->orderByDesc('date')
                ->value('date');

            if (
                $user->todays_note_placeholder
                && $user->todays_note_placeholder_created_from
                && $mostRecentNoteDate
                && $user->todays_note_placeholder_created_from->toDateString() === $mostRecentNoteDate
            ) {
                $props['previousContent'] = $this->textToProseMirror($user->todays_note_placeholder);
            } else {
                $props['previousContent'] = $this->textToProseMirror("What's on your plate today?");
            }
        }

        // Include summaries only for today's view, and only if fresh
        $isToday = $date === $today;
        $user = $request->user();
        $ttl = config('summaries.ttl_seconds', 86400);

        if ($isToday) {
            if ($user->weekly_summary_at && $user->weekly_summary_at->diffInSeconds(now()) < $ttl) {
                $props['weeklySummary'] = Str::markdown($user->weekly_summary);
            }
        }

        return Inertia::render('Home', $props);
    }

    /**
     * Convert plain text into a ProseMirror JSON document.
     *
     * Each non-empty line becomes a paragraph node. Empty lines are preserved
     * as empty paragraphs so the document structure feels natural.
     *
     * @return array<string, mixed>
     */
    protected function textToProseMirror(string $text): array
    {
        $lines = explode("\n", $text);
        $content = [];
        $taskItems = [];
        $bulletItems = [];

        $flushTaskList = function () use (&$content, &$taskItems) {
            if (! empty($taskItems)) {
                $content[] = ['type' => 'task_list', 'content' => $taskItems];
                $taskItems = [];
            }
        };

        $flushBulletList = function () use (&$content, &$bulletItems) {
            if (! empty($bulletItems)) {
                $content[] = ['type' => 'bullet_list', 'content' => $bulletItems];
                $bulletItems = [];
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Task list item: - [ ] or - [x]
            if (preg_match('/^- \[([ xX])\] (.+)$/', $trimmed, $m)) {
                $flushBulletList();
                $checked = strtolower($m[1]) === 'x';
                $taskItems[] = [
                    'type' => 'task_list_item',
                    'attrs' => ['checked' => $checked, 'timerSeconds' => 0, 'timerRunning' => false, 'timerStartedAt' => null],
                    'content' => [
                        ['type' => 'paragraph', 'content' => $this->parseInlineMarks($m[2])],
                    ],
                ];

                continue;
            }

            $flushTaskList();

            // Bullet list item: - text or * text
            if (preg_match('/^[-*] (.+)$/', $trimmed, $m)) {
                $bulletItems[] = [
                    'type' => 'list_item',
                    'content' => [
                        ['type' => 'paragraph', 'content' => $this->parseInlineMarks($m[1])],
                    ],
                ];

                continue;
            }

            $flushBulletList();

            // Heading: # text
            if (preg_match('/^# (.+)$/', $trimmed, $m)) {
                $content[] = [
                    'type' => 'heading',
                    'content' => $this->parseInlineMarks($m[1]),
                ];

                continue;
            }

            // Empty line
            if ($trimmed === '') {
                $content[] = ['type' => 'paragraph'];

                continue;
            }

            // Plain paragraph
            $content[] = [
                'type' => 'paragraph',
                'content' => $this->parseInlineMarks($trimmed),
            ];
        }

        $flushTaskList();
        $flushBulletList();

        if (empty($content)) {
            $content[] = ['type' => 'paragraph'];
        }

        return [
            'type' => 'doc',
            'content' => $content,
        ];
    }

    /**
     * Parse inline markdown marks (bold, italic) into ProseMirror text nodes.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseInlineMarks(string $text): array
    {
        $nodes = [];
        // Match **bold**, __bold__, *italic*, _italic_, or plain text
        $pattern = '/(\*\*(.+?)\*\*|__(.+?)__|\*(.+?)\*|_(.+?)_)/';
        $offset = 0;

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $matchText = $match[0];
                $matchPos = $match[1];

                // Add plain text before this match
                if ($matchPos > $offset) {
                    $nodes[] = ['type' => 'text', 'text' => substr($text, $offset, $matchPos - $offset)];
                }

                // Determine mark type and inner text
                if ($matches[2][$i][1] !== -1) {
                    // **bold**
                    $nodes[] = ['type' => 'text', 'text' => $matches[2][$i][0], 'marks' => [['type' => 'bold']]];
                } elseif ($matches[3][$i][1] !== -1) {
                    // __bold__
                    $nodes[] = ['type' => 'text', 'text' => $matches[3][$i][0], 'marks' => [['type' => 'bold']]];
                } elseif ($matches[4][$i][1] !== -1) {
                    // *italic*
                    $nodes[] = ['type' => 'text', 'text' => $matches[4][$i][0], 'marks' => [['type' => 'italic']]];
                } elseif ($matches[5][$i][1] !== -1) {
                    // _italic_
                    $nodes[] = ['type' => 'text', 'text' => $matches[5][$i][0], 'marks' => [['type' => 'italic']]];
                }

                $offset = $matchPos + strlen($matchText);
            }
        }

        // Add remaining plain text
        if ($offset < strlen($text)) {
            $nodes[] = ['type' => 'text', 'text' => substr($text, $offset)];
        }

        return $nodes ?: [['type' => 'text', 'text' => $text]];
    }
}
