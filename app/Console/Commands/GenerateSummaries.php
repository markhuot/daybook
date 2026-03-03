<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWeeklySummary;
use App\Models\User;
use Illuminate\Console\Command;

class GenerateSummaries extends Command
{
    protected $signature = 'summaries:generate {user : User ID or email address}';

    protected $description = 'Generate weekly summaries for a user';

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

        $this->info("Generating summaries for {$user->email}...");

        GenerateWeeklySummary::dispatchSync($user);
        $user->refresh();

        if ($user->weekly_summary) {
            $this->line('');
            $this->info('Weekly summary:');
            $this->line($user->weekly_summary);
        } else {
            $this->warn('No notes in the past 7 days — weekly summary cleared.');
        }

        $this->line('');
        $this->info('Done.');

        return self::SUCCESS;
    }
}
