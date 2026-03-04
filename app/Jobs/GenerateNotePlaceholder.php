<?php

namespace App\Jobs;

use App\Ai\Agents\NotePlaceholderAgent;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateNotePlaceholder implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, ExtractsNoteText, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public User $user,
    ) {}

    public function uniqueId(): string
    {
        return 'placeholder:'.$this->user->id;
    }

    public function handle(): void
    {
        $note = $this->user->notes()
            ->whereNotNull('content')
            ->orderByDesc('date')
            ->first();

        if (! $note) {
            return;
        }

        // Skip if we already generated a placeholder from this note
        if ($this->user->todays_note_placeholder_created_from?->toDateString() === $note->date) {
            return;
        }

        $noteText = trim($this->extractText($note->content));

        if (empty($noteText)) {
            return;
        }

        $response = (new NotePlaceholderAgent)->prompt("Today's entry:\n\n".$noteText."\n\nNow write tomorrow's starting point.");

        $this->user->update([
            'todays_note_placeholder' => (string) $response,
            'todays_note_placeholder_created_from' => $note->date,
        ]);
    }
}
