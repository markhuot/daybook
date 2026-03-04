<?php

namespace App\Jobs;

use App\Models\NoteEmbedding;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Embeddings;

class GenerateNoteEmbeddings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, ExtractsNoteText, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public User $user,
    ) {
        $this->uniqueFor = config('embeddings.debounce_seconds', 3600);
    }

    public function uniqueId(): string
    {
        return 'embeddings:'.$this->user->id;
    }

    public function handle(): void
    {
        $notes = $this->user->notes()
            ->whereNotNull('content')
            ->get();

        // Remove embeddings for notes that no longer have content
        NoteEmbedding::whereIn(
            'note_id',
            $this->user->notes()->whereNull('content')->select('id')
        )->delete();

        if ($notes->isEmpty()) {
            return;
        }

        // Determine which notes need new or updated embeddings
        $notesToEmbed = $notes->filter(function ($note) {
            $hash = hash('sha256', json_encode($note->content));
            $existing = $note->embedding;

            return ! $existing || $existing->content_hash !== $hash;
        });

        if ($notesToEmbed->isEmpty()) {
            return;
        }

        // Extract plain text from each note's ProseMirror content
        $texts = $notesToEmbed->map(fn ($note) => $this->extractText($note->content))->values();

        $response = Embeddings::for($texts->all())
            ->dimensions(config('embeddings.dimensions', 1536))
            ->generate(config('embeddings.provider', 'openai'), config('embeddings.model'));

        foreach ($notesToEmbed->values() as $i => $note) {
            NoteEmbedding::updateOrCreate(
                ['note_id' => $note->id],
                [
                    'embedding' => $response->embeddings[$i],
                    'content_hash' => hash('sha256', json_encode($note->content)),
                ],
            );
        }
    }
}
