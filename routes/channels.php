<?php

use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('note.{noteId}', function (User $user, int $noteId) {
    $note = Note::find($noteId);

    if ($note === null) {
        return false;
    }

    return $user->can('view', $note);
});
