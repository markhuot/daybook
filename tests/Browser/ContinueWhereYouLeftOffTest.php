<?php

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the suggestion button with static placeholder when today is empty', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/');

    $page->assertSee('Start with a suggestion');
    $page->assertSeeIn('.ProseMirror.placeholder-ghost', "What's on your plate today?");
});

it('shows the suggestion button with AI placeholder when one exists for the most recent note', function () {
    $user = User::factory()->create([
        'todays_note_placeholder' => 'You were working on the API refactor yesterday.',
        'todays_note_placeholder_created_from' => now()->subDay()->toDateString(),
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'worked on API']]]]],
    ]);

    $this->actingAs($user);

    $page = visit('/');

    $page->assertSee('Start with a suggestion');
    $page->assertSeeIn('.ProseMirror.placeholder-ghost', 'You were working on the API refactor yesterday.');
});

it('does not show the suggestion button when today has content', function () {
    $user = User::factory()->create();

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'already writing']]]]],
    ]);

    $this->actingAs($user);

    $page = visit('/');

    $page->assertDontSee('Start with a suggestion');
});

it('brings in placeholder text when clicking the suggestion button', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/');

    $page->assertSee('Start with a suggestion');
    $page->click('[data-testid="continue-button"]');
    $page->assertSeeIn('.ProseMirror:not(.placeholder-ghost)', "What's on your plate today?");
    $page->assertDontSee('Start with a suggestion');
});

it('brings in placeholder text when pressing Space', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/');

    $page->assertSee('Start with a suggestion');
    $page->click('.ProseMirror:not(.placeholder-ghost)');
    $page->keys('.ProseMirror:not(.placeholder-ghost)', ' ');
    $page->assertSeeIn('.ProseMirror:not(.placeholder-ghost)', "What's on your plate today?");
    $page->assertDontSee('Start with a suggestion');
});

it('places the cursor at the beginning of the note after accepting suggestion', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/');

    $page->click('[data-testid="continue-button"]');

    // Verify the cursor is at the very beginning of the editor content.
    // The native Selection API offset should be 0 within the first text node.
    $page->assertScript(
        'window.getSelection().anchorOffset',
        0,
    );
});
