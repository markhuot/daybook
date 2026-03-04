<?php

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows a user to view their own note', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create(['user_id' => $user->id, 'date' => now()->toDateString()]);

    expect($user->can('view', $note))->toBeTrue();
});

it('denies a user from viewing another user\'s note', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $note = Note::factory()->create(['user_id' => $owner->id, 'date' => now()->toDateString()]);

    expect($other->can('view', $note))->toBeFalse();
});

it('allows a user to update their own note', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create(['user_id' => $user->id, 'date' => now()->toDateString()]);

    expect($user->can('update', $note))->toBeTrue();
});

it('denies a user from updating another user\'s note', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $note = Note::factory()->create(['user_id' => $owner->id, 'date' => now()->toDateString()]);

    expect($other->can('update', $note))->toBeFalse();
});

it('denies anyone from deleting a note', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create(['user_id' => $user->id, 'date' => now()->toDateString()]);

    expect($user->can('delete', $note))->toBeFalse();
});

it('denies deleting even for the note owner', function () {
    $owner = User::factory()->create();
    $note = Note::factory()->create(['user_id' => $owner->id, 'date' => now()->toDateString()]);

    expect($owner->can('delete', $note))->toBeFalse();
});
