<?php

use App\Ai\Agents\NotePlaceholderAgent;
use App\Jobs\GenerateNotePlaceholder;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Job behavior tests ---

it('generates a placeholder from the most recent note', function () {
    NotePlaceholderAgent::fake(['You were debugging the login flow.']);

    $user = User::factory()->create(['timezone' => 'UTC']);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDays(2)->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Older note']]]]],
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Fixed login bug']]]]],
    ]);

    $job = new GenerateNotePlaceholder($user);
    $job->handle();

    $user->refresh();
    expect($user->todays_note_placeholder)->toBe('You were debugging the login flow.');
    expect($user->todays_note_placeholder_created_from->toDateString())->toBe(now()->subDay()->toDateString());

    NotePlaceholderAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Fixed login bug'));
});

it('does nothing when user has no notes', function () {
    NotePlaceholderAgent::fake();

    $user = User::factory()->create(['timezone' => 'UTC']);

    $job = new GenerateNotePlaceholder($user);
    $job->handle();

    $user->refresh();
    expect($user->todays_note_placeholder)->toBeNull();
    expect($user->todays_note_placeholder_created_from)->toBeNull();

    NotePlaceholderAgent::assertNeverPrompted();
});

it('does nothing when most recent note has null content', function () {
    NotePlaceholderAgent::fake();

    $user = User::factory()->create(['timezone' => 'UTC']);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => null,
    ]);

    $job = new GenerateNotePlaceholder($user);
    $job->handle();

    $user->refresh();
    expect($user->todays_note_placeholder)->toBeNull();

    NotePlaceholderAgent::assertNeverPrompted();
});

it('does nothing when most recent note has empty text content', function () {
    NotePlaceholderAgent::fake();

    $user = User::factory()->create(['timezone' => 'UTC']);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
    ]);

    $job = new GenerateNotePlaceholder($user);
    $job->handle();

    $user->refresh();
    expect($user->todays_note_placeholder)->toBeNull();

    NotePlaceholderAgent::assertNeverPrompted();
});

it('skips when placeholder already matches the most recent note date', function () {
    NotePlaceholderAgent::fake();

    $yesterday = now()->subDay()->toDateString();

    $user = User::factory()->create([
        'timezone' => 'UTC',
        'todays_note_placeholder' => 'Already generated.',
        'todays_note_placeholder_created_from' => $yesterday,
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => $yesterday,
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Some content']]]]],
    ]);

    $job = new GenerateNotePlaceholder($user);
    $job->handle();

    $user->refresh();
    expect($user->todays_note_placeholder)->toBe('Already generated.');

    NotePlaceholderAgent::assertNeverPrompted();
});

it('regenerates when a newer note exists than the stored placeholder date', function () {
    NotePlaceholderAgent::fake(['Updated suggestion.']);

    $user = User::factory()->create([
        'timezone' => 'UTC',
        'todays_note_placeholder' => 'Old suggestion.',
        'todays_note_placeholder_created_from' => now()->subDays(2)->toDateString(),
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'new stuff']]]]],
    ]);

    $job = new GenerateNotePlaceholder($user);
    $job->handle();

    $user->refresh();
    expect($user->todays_note_placeholder)->toBe('Updated suggestion.');
    expect($user->todays_note_placeholder_created_from->toDateString())->toBe(now()->subDay()->toDateString());
});
