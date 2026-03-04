<?php

use App\Jobs\GenerateNoteEmbeddings;
use App\Models\Note;
use App\Models\NoteEmbedding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

// --- Job dispatch tests ---

it('dispatches embedding job when a note is saved via steps', function () {
    Queue::fake();

    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]];

    $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [['stepType' => 'replace', 'from' => 0, 'to' => 0, 'slice' => ['content' => [['type' => 'text', 'text' => 'a']]]]],
        'clientID' => 'tab-embed',
        'doc' => $content,
    ]);

    Queue::assertPushed(GenerateNoteEmbeddings::class);
});

// --- Embedding generation tests ---

it('generates embeddings for notes with content', function () {
    Embeddings::fake();

    $user = User::factory()->create();

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Today was a good day']]]]],
    ]);

    $job = new GenerateNoteEmbeddings($user);
    $job->handle();

    expect(NoteEmbedding::count())->toBe(1);

    $embedding = NoteEmbedding::first();
    expect($embedding->embedding)->toBeArray();
    expect($embedding->content_hash)->not->toBeEmpty();
});

it('generates embeddings for multiple notes in a single batch', function () {
    Embeddings::fake();

    $user = User::factory()->create();

    foreach (range(0, 2) as $daysAgo) {
        Note::factory()->create([
            'user_id' => $user->id,
            'date' => now()->subDays($daysAgo)->toDateString(),
            'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Day {$daysAgo} notes"]]]]],
        ]);
    }

    $job = new GenerateNoteEmbeddings($user);
    $job->handle();

    expect(NoteEmbedding::count())->toBe(3);
});

it('skips notes whose content has not changed', function () {
    $fake = Embeddings::fake();

    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'unchanged']]]]];

    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => $content,
    ]);

    // First run — should generate
    $job = new GenerateNoteEmbeddings($user);
    $job->handle();

    $originalEmbedding = NoteEmbedding::first()->embedding;

    Embeddings::assertGenerated(fn () => true);

    // Second run with same content — should skip (embedding stays the same)
    $fake = Embeddings::fake();

    $job = new GenerateNoteEmbeddings($user->fresh());
    $job->handle();

    expect(NoteEmbedding::count())->toBe(1);
    expect(NoteEmbedding::first()->embedding)->toBe($originalEmbedding);
});

it('regenerates embedding when note content changes', function () {
    Embeddings::fake();

    $user = User::factory()->create();

    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'original']]]]],
    ]);

    $job = new GenerateNoteEmbeddings($user);
    $job->handle();

    $originalHash = NoteEmbedding::first()->content_hash;

    // Update content
    $note->update([
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'updated']]]]],
    ]);

    Embeddings::fake();

    $job = new GenerateNoteEmbeddings($user->fresh());
    $job->handle();

    expect(NoteEmbedding::count())->toBe(1);
    expect(NoteEmbedding::first()->content_hash)->not->toBe($originalHash);
});

it('does nothing when user has no notes', function () {
    Embeddings::fake();

    $user = User::factory()->create();

    $job = new GenerateNoteEmbeddings($user);
    $job->handle();

    expect(NoteEmbedding::count())->toBe(0);

    Embeddings::assertNothingGenerated();
});

it('cleans up embeddings for notes with null content', function () {
    Embeddings::fake();

    $user = User::factory()->create();

    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]],
    ]);

    // Generate initial embedding
    $job = new GenerateNoteEmbeddings($user);
    $job->handle();

    expect(NoteEmbedding::count())->toBe(1);

    // Set content to null (simulating deletion via controller keeping the row)
    $note->update(['content' => null]);

    Embeddings::fake();

    $job = new GenerateNoteEmbeddings($user->fresh());
    $job->handle();

    expect(NoteEmbedding::count())->toBe(0);
});
