<?php

namespace App\Jobs;

use App\Ai\Agents\WeeklySummaryAgent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateWeeklySummary implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, ExtractsNoteText, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public User $user,
    ) {
        $this->uniqueFor = config('summaries.debounce_seconds', 3600);
    }

    public function uniqueId(): string
    {
        return 'weekly:'.$this->user->id;
    }

    public function handle(): void
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

        $response = (new WeeklySummaryAgent)->prompt('<journal_entries>'.$notesText.'</journal_entries>');

        $this->user->update([
            'weekly_summary' => (string) $response,
            'weekly_summary_at' => now(),
        ]);
    }
}
