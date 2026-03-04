<?php

use App\Ai\Agents\NotePlaceholderAgent;
use App\Jobs\GenerateNotePlaceholder;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// --- Single user mode ---

it('generates placeholder for a user by email', function () {
    NotePlaceholderAgent::fake(['Tomorrow try finishing the auth work.']);

    $user = User::factory()->create(['email' => 'alice@example.com', 'timezone' => 'UTC']);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'auth work']]]]],
    ]);

    $this->artisan('placeholders:generate', ['user' => 'alice@example.com'])
        ->expectsOutputToContain('Placeholder:')
        ->expectsOutputToContain('Tomorrow try finishing the auth work.')
        ->assertSuccessful();

    $user->refresh();
    expect($user->todays_note_placeholder)->toBe('Tomorrow try finishing the auth work.');
});

it('generates placeholder for a user by ID', function () {
    NotePlaceholderAgent::fake(['Keep it up.']);

    $user = User::factory()->create(['timezone' => 'UTC']);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]],
    ]);

    $this->artisan('placeholders:generate', ['user' => $user->id])
        ->assertSuccessful();

    $user->refresh();
    expect($user->todays_note_placeholder)->toBe('Keep it up.');
});

it('fails when user is not found', function () {
    $this->artisan('placeholders:generate', ['user' => 'nobody@example.com'])
        ->expectsOutputToContain('User not found')
        ->assertFailed();
});

it('warns when user has no notes with content', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);

    $this->artisan('placeholders:generate', ['user' => $user->email])
        ->expectsOutputToContain('No notes with content found')
        ->assertSuccessful();
});

// --- Scheduled mode (no user argument) ---

it('dispatches jobs for users whose local time is 10pm', function () {
    Queue::fake();

    // Freeze at a specific UTC time
    $this->travelTo(\Carbon\Carbon::create(2026, 3, 3, 6, 0, 0, 'UTC'));

    // UTC+8 → 14:00 local → not 10pm
    $userSingapore = User::factory()->create(['timezone' => 'Asia/Singapore']);
    // UTC-4 → 02:00 local → not 10pm
    $userNY = User::factory()->create(['timezone' => 'America/New_York']);
    // UTC+9 → 15:00 local → not 10pm (but let's make one that IS 10pm)

    // For 06:00 UTC to be 22:00 local, we need UTC+16... that's not real.
    // Let's instead freeze at 14:00 UTC so UTC+8 is 22:00 (Singapore)
    $this->travelTo(\Carbon\Carbon::create(2026, 3, 3, 14, 0, 0, 'UTC'));

    $this->artisan('placeholders:generate')
        ->assertSuccessful();

    Queue::assertPushed(GenerateNotePlaceholder::class, function ($job) use ($userSingapore) {
        return $job->user->id === $userSingapore->id;
    });

    Queue::assertNotPushed(GenerateNotePlaceholder::class, function ($job) use ($userNY) {
        return $job->user->id === $userNY->id;
    });
});

it('does not dispatch jobs for users without a timezone', function () {
    Queue::fake();

    User::factory()->create(['timezone' => null]);

    $this->artisan('placeholders:generate')
        ->assertSuccessful();

    Queue::assertNotPushed(GenerateNotePlaceholder::class);
});
