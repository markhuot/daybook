<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteWindowLeftController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $timezone = $request->cookie('timezone', 'UTC');
        $today = Carbon::now($timezone)->toDateString();

        $before = (string) $request->query('before', '');
        $days = (int) $request->query('days', 10);
        $days = max(1, min($days, 30));

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
            abort(422, 'Invalid before date.');
        }

        $beforeDate = Carbon::createFromFormat('Y-m-d', $before);
        if (! $beforeDate || $beforeDate->toDateString() !== $before) {
            abort(422, 'Invalid before date.');
        }

        if ($before > $today) {
            abort(422, 'Invalid before date.');
        }

        $windowStart = $beforeDate->copy()->subDays($days)->toDateString();
        $windowEnd = $beforeDate->copy()->subDay()->toDateString();

        $windowNotes = $request->user()->notes()
            ->whereBetween('date', [$windowStart, $windowEnd])
            ->get()
            ->keyBy('date');

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

        return response()->json([
            'notes' => $notes,
        ]);
    }
}
