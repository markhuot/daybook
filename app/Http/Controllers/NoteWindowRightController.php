<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteWindowRightController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $timezone = $request->cookie('timezone', 'UTC');
        $today = Carbon::now($timezone)->toDateString();

        $after = (string) $request->query('after', '');
        $days = (int) $request->query('days', 10);
        $days = max(1, min($days, 30));

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $after)) {
            abort(422, 'Invalid after date.');
        }

        $afterDate = Carbon::createFromFormat('Y-m-d', $after);
        if (! $afterDate || $afterDate->toDateString() !== $after) {
            abort(422, 'Invalid after date.');
        }

        if ($after > $today) {
            return response()->json([
                'notes' => [],
            ]);
        }

        $windowStart = $afterDate->copy()->addDay()->toDateString();
        $rawWindowEnd = $afterDate->copy()->addDays($days)->toDateString();
        $windowEnd = min($rawWindowEnd, $today);

        if ($windowStart > $windowEnd) {
            return response()->json([
                'notes' => [],
            ]);
        }

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
