<?php

namespace App\Console\Commands;

use App\Jobs\GenerateNotePlaceholder;
use App\Models\User;
use Illuminate\Console\Command;

class GeneratePlaceholders extends Command
{
    protected $signature = 'placeholders:generate {user? : User ID or email address (omit to run for all users at 10pm local time)}';

    protected $description = 'Generate AI note placeholders for tomorrow';

    public function handle(): int
    {
        $identifier = $this->argument('user');

        if ($identifier) {
            return $this->generateForUser($identifier);
        }

        return $this->generateForEligibleUsers();
    }

    protected function generateForUser(string $identifier): int
    {
        $user = is_numeric($identifier)
            ? User::find($identifier)
            : User::where('email', $identifier)->first();

        if (! $user) {
            $this->error("User not found: {$identifier}");

            return self::FAILURE;
        }

        $this->info("Generating placeholder for {$user->email}...");

        GenerateNotePlaceholder::dispatchSync($user);
        $user->refresh();

        if ($user->todays_note_placeholder) {
            $this->line('');
            $this->info('Placeholder:');
            $this->line($user->todays_note_placeholder);
        } else {
            $this->warn('No notes with content found — placeholder not generated.');
        }

        $this->line('');
        $this->info('Done.');

        return self::SUCCESS;
    }

    protected function generateForEligibleUsers(): int
    {
        $now = now();
        $dispatched = 0;

        User::whereNotNull('timezone')
            ->each(function (User $user) use ($now, &$dispatched) {
                $localHour = $now->copy()->setTimezone($user->timezone)->hour;

                if ($localHour === 22) {
                    GenerateNotePlaceholder::dispatch($user);
                    $dispatched++;
                    $this->line("Dispatched placeholder job for {$user->email}");
                }
            });

        $this->info("Dispatched {$dispatched} placeholder job(s).");

        return self::SUCCESS;
    }
}
