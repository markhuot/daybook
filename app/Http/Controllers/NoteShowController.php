<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
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

        // If today's note has no content, offer the most recent note with content as a starting point
        if ($date === $today && ! $note?->content) {
            $previousNote = $request->user()->notes()
                ->where('date', '<', $date)
                ->whereNotNull('content')
                ->orderByDesc('date')
                ->first();

            if ($previousNote) {
                $props['previousContent'] = $previousNote->content;
            }
        }

        return Inertia::render('Home', $props);
    }
}
