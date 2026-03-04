<?php

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

// --- AI placeholder display tests ---

it('shows AI placeholder when created_from matches the most recent note', function () {
    $user = User::factory()->create([
        'todays_note_placeholder' => 'You were working on the API refactor.',
        'todays_note_placeholder_created_from' => now()->subDay()->toDateString(),
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'API work']]]]],
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'You were working on the API refactor.']]]],
        ])
    );
});

it('falls back to static placeholder when AI placeholder created_from does not match most recent note', function () {
    $user = User::factory()->create([
        'todays_note_placeholder' => 'Stale suggestion from two days ago.',
        'todays_note_placeholder_created_from' => now()->subDays(2)->toDateString(),
    ]);

    // Most recent note is from yesterday, but placeholder was created from 2 days ago
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'newer entry']]]]],
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDays(2)->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'older entry']]]]],
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "What's on your plate today?"]]]],
        ])
    );
});

it('falls back to static placeholder when AI placeholder exists but no notes exist', function () {
    $user = User::factory()->create([
        'todays_note_placeholder' => 'Some leftover suggestion.',
        'todays_note_placeholder_created_from' => now()->subDay()->toDateString(),
    ]);

    // The note the placeholder was created from has been deleted or doesn't exist
    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "What's on your plate today?"]]]],
        ])
    );
});

it('falls back to static placeholder when user has no AI placeholder at all', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "What's on your plate today?"]]]],
        ])
    );
});

it('does not show any placeholder when today already has content', function () {
    $user = User::factory()->create([
        'todays_note_placeholder' => 'Some suggestion.',
        'todays_note_placeholder_created_from' => now()->subDay()->toDateString(),
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'already writing']]]]],
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.content', fn ($content) => $content !== null)
        ->missing('previousContent')
    );
});

it('does not show placeholder when viewing a past date', function () {
    $user = User::factory()->create([
        'todays_note_placeholder' => 'Some suggestion.',
        'todays_note_placeholder_created_from' => now()->subDay()->toDateString(),
    ]);

    $pastDate = now()->subDays(2)->toDateString();

    $response = $this->actingAs($user)->get("/{$pastDate}");

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->missing('previousContent')
    );
});

it('converts multiline AI placeholder text into multiple ProseMirror paragraphs', function () {
    $user = User::factory()->create([
        'todays_note_placeholder' => "Line one.\nLine two.",
        'todays_note_placeholder_created_from' => now()->subDay()->toDateString(),
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday']]]]],
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Line one.']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Line two.']]],
            ],
        ])
    );
});

it('converts task list items in AI placeholder into ProseMirror task_list nodes', function () {
    $user = User::factory()->create([
        'todays_note_placeholder' => "- [ ] Finish the API refactor\n- [ ] Review pull request\n- [x] Deploy staging",
        'todays_note_placeholder_created_from' => now()->subDay()->toDateString(),
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday']]]]],
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [
                ['type' => 'task_list', 'content' => [
                    ['type' => 'task_list_item', 'attrs' => ['checked' => false, 'timerSeconds' => 0, 'timerRunning' => false, 'timerStartedAt' => null], 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Finish the API refactor']]],
                    ]],
                    ['type' => 'task_list_item', 'attrs' => ['checked' => false, 'timerSeconds' => 0, 'timerRunning' => false, 'timerStartedAt' => null], 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Review pull request']]],
                    ]],
                    ['type' => 'task_list_item', 'attrs' => ['checked' => true, 'timerSeconds' => 0, 'timerRunning' => false, 'timerStartedAt' => null], 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Deploy staging']]],
                    ]],
                ]],
            ],
        ])
    );
});

it('converts bullet list items in AI placeholder into ProseMirror bullet_list nodes', function () {
    $user = User::factory()->create([
        'todays_note_placeholder' => "- First item\n- Second item",
        'todays_note_placeholder_created_from' => now()->subDay()->toDateString(),
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday']]]]],
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [
                ['type' => 'bullet_list', 'content' => [
                    ['type' => 'list_item', 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First item']]],
                    ]],
                    ['type' => 'list_item', 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second item']]],
                    ]],
                ]],
            ],
        ])
    );
});

it('converts bold and italic markdown in AI placeholder into ProseMirror marks', function () {
    $user = User::factory()->create([
        'todays_note_placeholder' => 'Remember to **deploy** the *staging* build.',
        'todays_note_placeholder_created_from' => now()->subDay()->toDateString(),
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday']]]]],
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Remember to '],
                    ['type' => 'text', 'text' => 'deploy', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => ' the '],
                    ['type' => 'text', 'text' => 'staging', 'marks' => [['type' => 'italic']]],
                    ['type' => 'text', 'text' => ' build.'],
                ]],
            ],
        ])
    );
});

it('converts headings in AI placeholder into ProseMirror heading nodes', function () {
    $user = User::factory()->create([
        'todays_note_placeholder' => "# Work\n- [ ] Ship the feature",
        'todays_note_placeholder_created_from' => now()->subDay()->toDateString(),
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday']]]]],
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'content' => [['type' => 'text', 'text' => 'Work']]],
                ['type' => 'task_list', 'content' => [
                    ['type' => 'task_list_item', 'attrs' => ['checked' => false, 'timerSeconds' => 0, 'timerRunning' => false, 'timerStartedAt' => null], 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ship the feature']]],
                    ]],
                ]],
            ],
        ])
    );
});
