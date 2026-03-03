<?php

use App\Ai\Agents\WeeklySummaryAgent;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates summaries for a user by email', function () {
    WeeklySummaryAgent::fake(['Weekly result.']);

    $user = User::factory()->create(['email' => 'alice@example.com', 'timezone' => 'UTC']);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]],
    ]);

    $this->artisan('summaries:generate', ['user' => 'alice@example.com'])
        ->expectsOutputToContain('Weekly summary:')
        ->expectsOutputToContain('Weekly result.')
        ->assertSuccessful();

    $user->refresh();
    expect($user->weekly_summary)->toBe('Weekly result.');
});

it('generates summaries for a user by ID', function () {
    WeeklySummaryAgent::fake(['Weekly by ID.']);

    $user = User::factory()->create(['timezone' => 'UTC']);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]],
    ]);

    $this->artisan('summaries:generate', ['user' => $user->id])
        ->assertSuccessful();

    $user->refresh();
    expect($user->weekly_summary)->toBe('Weekly by ID.');
});

it('fails when user is not found', function () {
    $this->artisan('summaries:generate', ['user' => 'nobody@example.com'])
        ->expectsOutputToContain('User not found')
        ->assertFailed();
});

it('warns when no notes exist in the window', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);

    $this->artisan('summaries:generate', ['user' => $user->email])
        ->expectsOutputToContain('No notes in the past 7 days')
        ->assertSuccessful();
});
