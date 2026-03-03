<?php

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the continue button when there is a previous day with text and today is empty', function () {
    $user = User::factory()->create();
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday notes']]]]],
    ]);

    $this->actingAs($user);

    $page = visit('/');

    $page->assertSee('Continue where you left off');
});

it('does not show the continue button when there is no previous note', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/');

    $page->assertDontSee('Continue where you left off');
});

it('brings in previous text when clicking the continue button', function () {
    $user = User::factory()->create();
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday notes']]]]],
    ]);

    $this->actingAs($user);

    $page = visit('/');

    $page->assertSee('Continue where you left off');
    $page->click('[data-testid="continue-button"]');
    $page->assertSeeIn('.ProseMirror:not(.placeholder-ghost)', 'yesterday notes');
    $page->assertDontSee('Continue where you left off');
});

it('brings in previous text when pressing Space', function () {
    $user = User::factory()->create();
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday notes']]]]],
    ]);

    $this->actingAs($user);

    $page = visit('/');

    $page->assertSee('Continue where you left off');
    $page->click('.ProseMirror:not(.placeholder-ghost)');
    $page->keys('.ProseMirror:not(.placeholder-ghost)', ' ');
    $page->assertSeeIn('.ProseMirror:not(.placeholder-ghost)', 'yesterday notes');
    $page->assertDontSee('Continue where you left off');
});

it('places the cursor at the beginning of the note after continuing', function () {
    $user = User::factory()->create();
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday notes']]]]],
    ]);

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
