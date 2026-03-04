<?php

namespace App\Console\Commands;

use App\Jobs\GenerateNoteEmbeddings;
use App\Models\User;
use Illuminate\Console\Command;

class GenerateEmbeddings extends Command
{
    protected $signature = 'embeddings:generate {user : User ID or email address}';

    protected $description = 'Generate note embeddings for a user';

    public function handle(): int
    {
        $identifier = $this->argument('user');

        $user = is_numeric($identifier)
            ? User::find($identifier)
            : User::where('email', $identifier)->first();

        if (! $user) {
            $this->error("User not found: {$identifier}");

            return self::FAILURE;
        }

        $noteCount = $user->notes()->whereNotNull('content')->count();

        if ($noteCount === 0) {
            $this->warn("No notes with content found for {$user->email}.");

            return self::SUCCESS;
        }

        $this->info("Generating embeddings for {$user->email} ({$noteCount} note(s))...");

        GenerateNoteEmbeddings::dispatchSync($user);

        $embeddingCount = $user->notes()->whereHas('embedding')->count();

        $this->info("Done. {$embeddingCount} embedding(s) generated.");

        return self::SUCCESS;
    }
}
