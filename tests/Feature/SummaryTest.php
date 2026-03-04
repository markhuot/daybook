<?php

use App\Ai\Agents\WeeklySummaryAgent;
use App\Jobs\GenerateWeeklySummary;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

// --- Job dispatch tests ---

it('dispatches weekly summary job when a note is saved via steps', function () {
    Queue::fake();

    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]];

    $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [['stepType' => 'replace', 'from' => 0, 'to' => 0, 'slice' => ['content' => [['type' => 'text', 'text' => 'a']]]]],
        'clientID' => 'tab-summary',
        'doc' => $content,
    ]);

    Queue::assertPushed(GenerateWeeklySummary::class);
});

it('persists the user timezone from the cookie on save', function () {
    Queue::fake();

    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]];

    $this->actingAs($user)
        ->withCredentials()
        ->withUnencryptedCookie('timezone', 'America/New_York')
        ->postJson('/note/steps', [
            'version' => 0,
            'steps' => [['stepType' => 'replace', 'from' => 0, 'to' => 0, 'slice' => ['content' => [['type' => 'text', 'text' => 'a']]]]],
            'clientID' => 'tab-tz',
            'doc' => $content,
        ]);

    $user->refresh();
    expect($user->timezone)->toBe('America/New_York');
});

// --- Summary display tests ---

it('includes summary props as HTML when viewing today with fresh summaries', function () {
    $user = User::factory()->create([
        'weekly_summary' => '**Weekly** recap',
        'weekly_summary_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Home')
        ->where('weeklySummary', fn ($val) => str_contains($val, '<strong>Weekly</strong>'))
    );
});

it('does not include summaries when viewing a past date', function () {
    $user = User::factory()->create([
        'weekly_summary' => 'Weekly recap',
        'weekly_summary_at' => now(),
    ]);

    $pastDate = now()->subDays(2)->toDateString();
    $response = $this->actingAs($user)->get("/{$pastDate}");

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Home')
        ->missing('weeklySummary')
    );
});

it('does not include summaries when they are stale', function () {
    $user = User::factory()->create([
        'weekly_summary' => 'Weekly recap',
        'weekly_summary_at' => now()->subHours(25),
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Home')
        ->missing('weeklySummary')
    );
});

it('does not include summaries when they are null', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Home')
        ->missing('weeklySummary')
    );
});

// --- Weekly summary job tests ---

it('generates a weekly summary from the past 7 days of notes', function () {
    WeeklySummaryAgent::fake(['Your week was productive.']);

    $user = User::factory()->create(['timezone' => 'UTC']);

    // Create notes for the past 3 days
    foreach (range(0, 2) as $daysAgo) {
        Note::factory()->create([
            'user_id' => $user->id,
            'date' => now()->subDays($daysAgo)->toDateString(),
            'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Day {$daysAgo} notes"]]]]],
        ]);
    }

    $job = new GenerateWeeklySummary($user);
    $job->handle();

    $user->refresh();
    expect($user->weekly_summary)->toBe('Your week was productive.');
    expect($user->weekly_summary_at)->not->toBeNull();

    WeeklySummaryAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Day'));
});

it('clears weekly summary when no notes exist in the window', function () {
    WeeklySummaryAgent::fake();

    $user = User::factory()->create([
        'timezone' => 'UTC',
        'weekly_summary' => 'Old summary',
        'weekly_summary_at' => now()->subDay(),
    ]);

    $job = new GenerateWeeklySummary($user);
    $job->handle();

    $user->refresh();
    expect($user->weekly_summary)->toBeNull();
    expect($user->weekly_summary_at)->toBeNull();

    WeeklySummaryAgent::assertNeverPrompted();
});

// --- Text extraction tests ---

it('extracts text from nested ProseMirror JSON', function () {
    WeeklySummaryAgent::fake(function (string $prompt) {
        // Verify the extracted text contains all text nodes
        expect($prompt)
            ->toContain('Hello ')
            ->toContain('world')
            ->toContain('Item one');

        return 'Summary text.';
    });

    $user = User::factory()->create(['timezone' => 'UTC']);

    $content = [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello '],
                    ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]],
                ],
            ],
            [
                'type' => 'bullet_list',
                'content' => [
                    [
                        'type' => 'list_item',
                        'content' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Item one']]],
                        ],
                    ],
                ],
            ],
        ],
    ];

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => $content,
    ]);

    $job = new GenerateWeeklySummary($user);
    $job->handle();

    $user->refresh();
    expect($user->weekly_summary)->toBe('Summary text.');
});

it('handles empty ProseMirror docs gracefully', function () {
    WeeklySummaryAgent::fake(['Nothing much happened.']);

    $user = User::factory()->create(['timezone' => 'UTC']);

    // Note with empty content — whereNotNull('content') should still find it,
    // but extractText should produce empty text
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
    ]);

    // The note has content (not null) but no text nodes.
    // The job should still try to generate (the LLM will get an empty string).
    $job = new GenerateWeeklySummary($user);
    $job->handle();

    $user->refresh();
    expect($user->weekly_summary)->toBe('Nothing much happened.');
});
