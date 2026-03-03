<?php

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Validation tests ---

it('rejects note update when content is not an array or null', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/note', [
        'content' => 'just a string',
    ])->assertSessionHasErrors('content');
});

it('rejects note update when content is an integer', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/note', [
        'content' => 42,
    ])->assertSessionHasErrors('content');
});

it('accepts note update when content is null', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->put('/note', [
        'content' => null,
    ]);

    $response->assertRedirect();
});

it('accepts note update when content is a valid array', function () {
    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]];

    $response = $this->actingAs($user)->put('/note', [
        'content' => $content,
    ]);

    $response->assertRedirect();

    $note = Note::where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->content)->toBe($content);
});

it('accepts note update when content key is omitted', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->put('/note', []);

    $response->assertRedirect();
});

// --- Update behavior tests ---

it('creates a new note on first save', function () {
    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'first entry']]]]];

    $this->assertDatabaseMissing('notes', ['user_id' => $user->id]);

    $this->actingAs($user)->put('/note', ['content' => $content]);

    $note = Note::where('user_id', $user->id)->where('date', now()->toDateString())->first();
    expect($note)->not->toBeNull();
    expect($note->content)->toBe($content);
});

it('updates an existing note for today', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'original']]]]],
    ]);

    $newContent = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'updated']]]]];

    $this->actingAs($user)->put('/note', ['content' => $newContent]);

    $note->refresh();
    expect($note->content)->toBe($newContent);
});

it('deletes the note when content is set to null', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'will be deleted']]]]],
    ]);

    $this->actingAs($user)->put('/note', ['content' => null]);

    $this->assertDatabaseMissing('notes', ['id' => $note->id]);
});

it('deletes the note when content is an empty ProseMirror doc', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'will be deleted']]]]],
    ]);

    $this->actingAs($user)->put('/note', [
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
    ]);

    $this->assertDatabaseMissing('notes', ['id' => $note->id]);
});

it('never modifies past notes via the update endpoint', function () {
    $user = User::factory()->create();
    $pastDate = now()->subDay()->toDateString();

    $originalContent = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'past entry']]]]];
    $pastNote = Note::factory()->create([
        'user_id' => $user->id,
        'date' => $pastDate,
        'content' => $originalContent,
    ]);

    $newContent = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'new content']]]]];
    $this->actingAs($user)->put('/note', ['content' => $newContent]);

    $pastNote->refresh();
    expect($pastNote->content)->toBe($originalContent);

    // Today's note should have the new content
    $todayNote = Note::where('user_id', $user->id)->where('date', now()->toDateString())->first();
    expect($todayNote->content)->toBe($newContent);
});

it('requires authentication to update a note', function () {
    $this->put('/note', ['content' => null])->assertRedirect('/login');
});
