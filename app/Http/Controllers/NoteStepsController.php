<?php

namespace App\Http\Controllers;

use App\Events\NoteStepsAccepted;
use App\Jobs\GenerateNoteEmbeddings;
use App\Jobs\GenerateWeeklySummary;
use Carbon\Carbon;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NoteStepsController extends Controller
{
    /**
     * Accept steps from a client (POST /note/steps).
     *
     * Uses Option B from the spec: trust the client, skip server-side step
     * application. The client sends the resulting document alongside the steps.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'version' => 'required|integer|min:0',
            'steps' => 'required|array|min:1',
            'clientID' => 'required|string',
            'doc' => 'required|array',
        ]);

        $user = $request->user();
        $timezone = $request->cookie('timezone', 'UTC');
        $today = Carbon::now($timezone)->toDateString();

        $requestVersion = (int) $request->input('version');
        $steps = $request->input('steps');
        $clientID = $request->input('clientID');
        $doc = $request->input('doc');

        // Acquire a lock on the note to serialize concurrent step submissions
        $lockKey = "note_lock:{$user->id}:{$today}";

        return Cache::lock($lockKey, 10)->block(5, function () use ($user, $today, $timezone, $requestVersion, $steps, $clientID, $doc) {
            // Load or create the note
            $note = $user->notes()->where('date', $today)->first();

            if (! $note) {
                $note = $user->notes()->create([
                    'date' => $today,
                    'content' => $doc,
                    'version' => count($steps),
                ]);

                // Store steps in Redis
                $this->storeStepsInRedis($user->id, $today, $steps, $clientID, 0);

                // Broadcast to other clients (non-fatal if Reverb is down)
                try {
                    broadcast(new NoteStepsAccepted(
                        note: $note,
                        version: $note->version,
                        steps: $steps,
                        clientIDs: array_fill(0, count($steps), $clientID),
                    ));
                } catch (BroadcastException $e) {
                    Log::warning('Failed to broadcast NoteStepsAccepted: '.$e->getMessage());
                }

                // Persist timezone and dispatch debounced background jobs
                $this->dispatchBackgroundJobs($user, $timezone);

                return response()->json(['version' => $note->version]);
            }

            // Check version match
            if ($requestVersion !== (int) $note->version) {
                // Return 409 with missing steps so the client can catch up
                $missingSteps = $this->getStepsFromRedis($user->id, $today, $requestVersion);

                return response()->json([
                    'version' => (int) $note->version,
                    'steps' => array_column($missingSteps, 'step'),
                    'clientIDs' => array_column($missingSteps, 'clientID'),
                ], 409);
            }

            // Accept the steps
            $newVersion = (int) $note->version + count($steps);

            $note->update([
                'content' => $doc,
                'version' => $newVersion,
            ]);

            // Store steps in Redis
            $this->storeStepsInRedis($user->id, $today, $steps, $clientID, $requestVersion);

            // Broadcast to other clients (non-fatal if Reverb is down)
            try {
                broadcast(new NoteStepsAccepted(
                    note: $note,
                    version: $newVersion,
                    steps: $steps,
                    clientIDs: array_fill(0, count($steps), $clientID),
                ));
            } catch (BroadcastException $e) {
                Log::warning('Failed to broadcast NoteStepsAccepted: '.$e->getMessage());
            }

            // Persist timezone and dispatch debounced background jobs
            $this->dispatchBackgroundJobs($user, $timezone);

            return response()->json(['version' => $newVersion]);
        });
    }

    /**
     * Catch-up endpoint (GET /note/steps?since={version}).
     *
     * Returns steps that happened after the given version so a reconnecting
     * client can catch up without a full document reload.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'since' => 'required|integer|min:0',
        ]);

        $user = $request->user();
        $timezone = $request->cookie('timezone', 'UTC');
        $today = Carbon::now($timezone)->toDateString();
        $since = (int) $request->input('since');

        $note = $user->notes()->where('date', $today)->first();

        if (! $note) {
            return response()->json([
                'version' => 0,
                'steps' => [],
                'clientIDs' => [],
            ]);
        }

        $redisKey = "note_steps:{$user->id}:{$today}";

        // Check if Redis still has the steps
        if (! Redis::exists($redisKey)) {
            if ($since < (int) $note->version) {
                // Steps have expired from Redis — client must do a full reload
                return response()->json([
                    'message' => 'Steps expired. Please reload the document.',
                ], 410);
            }

            // Client is up to date, no steps to return
            return response()->json([
                'version' => (int) $note->version,
                'steps' => [],
                'clientIDs' => [],
            ]);
        }

        $missingSteps = $this->getStepsFromRedis($user->id, $today, $since);

        if (empty($missingSteps) && $since < (int) $note->version) {
            // Redis has the key but not the steps we need — they were trimmed
            return response()->json([
                'message' => 'Steps expired. Please reload the document.',
            ], 410);
        }

        return response()->json([
            'version' => (int) $note->version,
            'steps' => array_column($missingSteps, 'step'),
            'clientIDs' => array_column($missingSteps, 'clientID'),
        ]);
    }

    /**
     * Persist the user's timezone and dispatch debounced background jobs.
     *
     * Both jobs use ShouldBeUnique, so rapid step batches during active typing
     * will not queue duplicate jobs. The 30-second delay ensures the job runs
     * against a document with at least 30 seconds of edits, rather than firing
     * on the very first keystroke.
     */
    private function dispatchBackgroundJobs(mixed $user, string $timezone): void
    {
        $user->update(['timezone' => $timezone]);

        GenerateWeeklySummary::dispatch($user)->delay(30);
        GenerateNoteEmbeddings::dispatch($user)->delay(30);
    }

    /**
     * Store steps in the Redis sorted set.
     */
    private function storeStepsInRedis(int $userId, string $date, array $steps, string $clientID, int $baseVersion): void
    {
        $redisKey = "note_steps:{$userId}:{$date}";

        foreach ($steps as $i => $step) {
            $version = $baseVersion + $i + 1;
            $member = json_encode([
                'version' => $version,
                'step' => $step,
                'clientID' => $clientID,
            ]);

            Redis::zadd($redisKey, $version, $member);
        }

        // Reset TTL to 1 hour
        Redis::expire($redisKey, 3600);
    }

    /**
     * Get steps from Redis since a given version.
     */
    private function getStepsFromRedis(int $userId, string $date, int $sinceVersion): array
    {
        $redisKey = "note_steps:{$userId}:{$date}";

        // ZRANGEBYSCORE with exclusive lower bound (sinceVersion, +inf)
        $members = Redis::zrangebyscore($redisKey, '('.$sinceVersion, '+inf');

        return array_map(function ($member) {
            return json_decode($member, true);
        }, $members);
    }
}
