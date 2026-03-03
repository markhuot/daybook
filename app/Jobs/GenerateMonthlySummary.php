<?php

namespace App\Jobs;

use App\Ai\Agents\MonthlySummaryAgent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMonthlySummary implements ShouldBeUnique, ShouldQueue
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
        return 'monthly:'.$this->user->id;
    }

    public function handle(): void
    {
        $today = now($this->user->timezone ?? 'UTC')->toDateString();

        $notes = $this->user->notes()
            ->whereBetween('date', [
                Carbon::parse($today)->subDays(29)->toDateString(),
                $today,
            ])
            ->whereNotNull('content')
            ->orderBy('date')
            ->get();

        if ($notes->isEmpty()) {
            $this->user->update([
                'monthly_summary' => null,
                'monthly_summary_at' => null,
            ]);

            return;
        }

        $notesText = $this->formatNotes($notes);

        $response = (new MonthlySummaryAgent)->prompt($notesText);

        $this->user->update([
            'monthly_summary' => (string) $response,
            'monthly_summary_at' => now(),
        ]);
    }
}
