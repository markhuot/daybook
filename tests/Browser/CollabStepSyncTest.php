<?php

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sends steps to the server when typing in the editor', function () {
    $user = User::factory()->create();

    // Pre-create a note so the placeholder ghost doesn't appear
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
        'version' => 0,
    ]);

    $this->actingAs($user);

    $page = visit('/');

    // Click and type individual characters into the editor
    $page->click('.ProseMirror[contenteditable]');
    $page->keys('.ProseMirror[contenteditable]', ['H', 'e', 'l', 'l', 'o']);

    // Wait for the debounced POST (100ms) + network time
    $page->wait(2);

    // Verify the note was updated with a version > 0
    $note = Note::where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->version)->toBeGreaterThan(0);

    $page->assertNoJavaScriptErrors();
});

it('increments version correctly across multiple typing bursts', function () {
    $user = User::factory()->create();

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
        'version' => 0,
    ]);

    $this->actingAs($user);

    $page = visit('/');

    // First burst of typing
    $page->click('.ProseMirror[contenteditable]');
    $page->keys('.ProseMirror[contenteditable]', ['A', 'B', 'C']);

    // Wait for the first POST to complete
    $page->wait(2);

    $note = Note::where('user_id', $user->id)->first();
    $versionAfterFirst = $note->version;
    expect($versionAfterFirst)->toBeGreaterThan(0);

    // Second burst of typing
    $page->keys('.ProseMirror[contenteditable]', ['D', 'E', 'F']);

    // Wait for the second POST
    $page->wait(2);

    $note->refresh();
    expect($note->version)->toBeGreaterThan($versionAfterFirst);

    $page->assertNoJavaScriptErrors();
});

it('does not produce JavaScript errors during collab sync', function () {
    $user = User::factory()->create();

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
        'version' => 0,
    ]);

    $this->actingAs($user);

    $page = visit('/');

    $page->click('.ProseMirror[contenteditable]');
    $page->keys('.ProseMirror[contenteditable]', ['T', 'e', 's', 't']);

    // Wait for the step to be sent
    $page->wait(2);

    // Verify no JS errors occurred during the whole flow
    $page->assertNoJavaScriptErrors();
});
