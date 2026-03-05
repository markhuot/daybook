<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;

class RunPrompt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public string $prompt,
    ) {}

    public function handle(): void
    {
        $home = env('HOME', posix_getpwuid(posix_geteuid())['dir'] ?? '/home/ubuntu');
        $opencodeDir = $home.'/.opencode/bin';

        $env = [
            'HOME' => $home,
            'PATH' => $opencodeDir.':/usr/local/bin:/usr/bin:/bin',
        ];

        $process = new Process(
            command: ['opencode', 'run', $this->prompt],
            cwd: base_path(),
            env: $env,
            timeout: 300,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                'opencode run failed: '.$process->getErrorOutput()
            );
        }
    }
}
