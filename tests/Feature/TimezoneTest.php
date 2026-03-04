<?php

use App\Models\Note;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

// --- NoteShowController timezone tests ---

it('uses the timezone cookie to determine today when viewing notes', function () {
    // Server clock: 2026-03-03 03:00 UTC
    // In America/Los_Angeles (UTC-8): 2026-03-02 19:00 → today is March 2nd
    $this->travelTo(Carbon::create(2026, 3, 3, 3, 0, 0, 'UTC'));

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withUnencryptedCookie('timezone', 'America/Los_Angeles')
        ->get('/');

    $response->assertStatus(200);
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.date', '2026-03-02')
    );
});

it('defaults to UTC when no timezone cookie is present', function () {
    // Server clock: 2026-03-03 03:00 UTC → today is March 3rd in UTC
    $this->travelTo(Carbon::create(2026, 3, 3, 3, 0, 0, 'UTC'));

    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.date', '2026-03-03')
    );
});

it('loads the correct note for the timezone-adjusted today', function () {
    // Server clock: 2026-03-03 03:00 UTC
    // In LA (UTC-8), today is March 2nd
    $this->travelTo(Carbon::create(2026, 3, 3, 3, 0, 0, 'UTC'));

    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'march 2nd note']]]]];

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => '2026-03-02',
        'content' => $content,
    ]);

    $response = $this->actingAs($user)
        ->withUnencryptedCookie('timezone', 'America/Los_Angeles')
        ->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.id', fn ($id) => $id !== null)
        ->where('note.date', '2026-03-02')
        ->where('note.content', $content)
    );
});

it('applies the future date guard using timezone-adjusted today', function () {
    // Server clock: 2026-03-03 03:00 UTC
    // In LA, today is March 2nd — so March 3rd is "future" for this user
    $this->travelTo(Carbon::create(2026, 3, 3, 3, 0, 0, 'UTC'));

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withUnencryptedCookie('timezone', 'America/Los_Angeles')
        ->get('/2026-03-03');

    $response->assertStatus(425);
});

it('allows viewing a date that is today in the user timezone but past in UTC', function () {
    // Server clock: 2026-03-04 03:00 UTC → UTC today is March 4th
    // In LA (UTC-8), it's still March 3rd
    // Visiting /2026-03-03 should be allowed — it's "today" in LA
    $this->travelTo(Carbon::create(2026, 3, 4, 3, 0, 0, 'UTC'));

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withUnencryptedCookie('timezone', 'America/Los_Angeles')
        ->get('/2026-03-03');

    $response->assertStatus(200);
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.date', '2026-03-03')
    );
});

it('anchors the notes window to the timezone-adjusted today', function () {
    // Server clock: 2026-03-03 03:00 UTC
    // In LA, today is March 2nd
    $this->travelTo(Carbon::create(2026, 3, 3, 3, 0, 0, 'UTC'));

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withUnencryptedCookie('timezone', 'America/Los_Angeles')
        ->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notes', function ($notes) {
            $notes = json_decode(json_encode($notes), true);
            $keys = array_keys($notes);

            // Window should include March 2nd (timezone today)
            expect($keys)->toContain('2026-03-02');

            // Window should NOT include March 3rd (future in LA)
            expect($keys)->not->toContain('2026-03-03');

            // Window is 10 dates: today minus 9 through today
            expect(count($notes))->toBe(10);

            return true;
        })
    );
});

it('offers static placeholder based on timezone-adjusted today', function () {
    // Server clock: 2026-03-03 03:00 UTC
    // In LA, today is March 2nd — and March 2nd has no note
    $this->travelTo(Carbon::create(2026, 3, 3, 3, 0, 0, 'UTC'));

    $user = User::factory()->create();

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => '2026-03-01',
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'march 1st entry']]]]],
    ]);

    $response = $this->actingAs($user)
        ->withUnencryptedCookie('timezone', 'America/Los_Angeles')
        ->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.date', '2026-03-02')
        ->where('note.content', null)
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "What's on your plate today?"]]]],
        ])
    );
});

// --- NoteStepsController timezone tests ---

it('saves steps to the timezone-adjusted date', function () {
    // Server clock: 2026-03-03 03:00 UTC
    // In LA, today is March 2nd — note should be saved to March 2nd
    $this->travelTo(Carbon::create(2026, 3, 3, 3, 0, 0, 'UTC'));

    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'late night in LA']]]]];

    $this->actingAs($user)
        ->withCredentials()
        ->withUnencryptedCookie('timezone', 'America/Los_Angeles')
        ->postJson('/note/steps', [
            'version' => 0,
            'steps' => [['stepType' => 'replace', 'from' => 0, 'to' => 0, 'slice' => ['content' => [['type' => 'text', 'text' => 'a']]]]],
            'clientID' => 'tab-tz',
            'doc' => $content,
        ]);

    $note = Note::where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->date)->toBe('2026-03-02');
});

it('updates the correct note when timezone shifts today', function () {
    // Server clock: 2026-03-03 03:00 UTC
    // In LA, today is March 2nd
    $this->travelTo(Carbon::create(2026, 3, 3, 3, 0, 0, 'UTC'));

    $user = User::factory()->create();
    $oldContent = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'original']]]]];
    $newContent = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'updated']]]]];

    // Existing note for March 2nd (LA today)
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => '2026-03-02',
        'content' => $oldContent,
        'version' => 0,
    ]);

    // Also a note for March 3rd (UTC today, but not LA today)
    $utcNote = Note::factory()->create([
        'user_id' => $user->id,
        'date' => '2026-03-03',
        'content' => $oldContent,
        'version' => 0,
    ]);

    $this->actingAs($user)
        ->withCredentials()
        ->withUnencryptedCookie('timezone', 'America/Los_Angeles')
        ->postJson('/note/steps', [
            'version' => 0,
            'steps' => [['stepType' => 'replace', 'from' => 0, 'to' => 0, 'slice' => ['content' => [['type' => 'text', 'text' => 'a']]]]],
            'clientID' => 'tab-tz',
            'doc' => $newContent,
        ]);

    // March 2nd note (LA today) should be updated
    $note->refresh();
    expect($note->content['content'][0]['content'][0]['text'])->toBe('updated');

    // March 3rd note (UTC today) should be untouched
    $utcNote->refresh();
    expect($utcNote->content['content'][0]['content'][0]['text'])->toBe('original');
});
