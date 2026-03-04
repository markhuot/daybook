<?php

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('redirects guests to login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('returns an empty note for today when none exists', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Home')
        ->where('note.id', null)
        ->where('note.date', now()->toDateString())
        ->where('note.content', null)
    );

    // No row should be created just from viewing
    $this->assertDatabaseMissing('notes', [
        'user_id' => $user->id,
        'date' => now()->toDateString(),
    ]);
});

it('loads an existing note for today', function () {
    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => $content,
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.id', $note->id)
        ->where('note.content', $content)
    );

    expect(Note::where('user_id', $user->id)->count())->toBe(1);
});

it('does not create rows when browsing dates', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/');
    $this->actingAs($user)->get('/'.now()->subDay()->toDateString());
    $this->actingAs($user)->get('/'.now()->subDays(2)->toDateString());

    expect(Note::where('user_id', $user->id)->count())->toBe(0);
});

it('saves note content', function () {
    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]];

    $response = $this->actingAs($user)->put('/note', [
        'content' => $content,
    ]);

    $response->assertRedirect();

    $note = Note::where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->date)->toBe(now()->toDateString());
    expect($note->content)->toBe($content);
});

it('deletes the row when content is cleared', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]],
    ]);

    // Send null content
    $this->actingAs($user)->put('/note', ['content' => null]);

    $this->assertDatabaseMissing('notes', ['id' => $note->id]);
});

it('deletes the row when content is an empty doc', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]],
    ]);

    // Send a doc with only empty paragraphs — no real text
    $this->actingAs($user)->put('/note', [
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
    ]);

    $this->assertDatabaseMissing('notes', ['id' => $note->id]);
});

it('creates the row on first save when no note exists yet', function () {
    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'new day']]]]];

    $this->assertDatabaseMissing('notes', ['user_id' => $user->id, 'date' => now()->toDateString()]);

    $this->actingAs($user)->put('/note', ['content' => $content]);

    $note = Note::where('user_id', $user->id)->where('date', now()->toDateString())->first();
    expect($note)->not->toBeNull();
    expect($note->content)->toBe($content);
});

it('requires authentication to save a note', function () {
    $this->put('/note', ['content' => null])->assertRedirect('/login');
});

// --- Navigation tests ---

it('can view a past date via the date route', function () {
    $user = User::factory()->create();
    $pastDate = now()->subDays(3)->toDateString();

    $response = $this->actingAs($user)->get("/{$pastDate}");

    $response->assertStatus(200);
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Home')
        ->where('note.date', $pastDate)
    );
});

it('loads existing content for a past date', function () {
    $user = User::factory()->create();
    $pastDate = now()->subDays(2)->toDateString();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'old entry']]]]];

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => $pastDate,
        'content' => $content,
    ]);

    $response = $this->actingAs($user)->get("/{$pastDate}");

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.date', $pastDate)
        ->where('note.content', $content)
    );
});

it('redirects future dates to today', function () {
    $user = User::factory()->create();
    $futureDate = now()->addDay()->toDateString();

    $response = $this->actingAs($user)->get("/{$futureDate}");

    $response->assertStatus(425);
});

it('does not create a note for a future date', function () {
    $user = User::factory()->create();
    $futureDate = now()->addDay()->toDateString();

    $this->actingAs($user)->get("/{$futureDate}");

    $this->assertDatabaseMissing('notes', [
        'user_id' => $user->id,
        'date' => $futureDate,
    ]);
});

// --- Editability tests ---

it('returns today date for the home route so frontend can determine editability', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.date', now()->toDateString())
    );
});

it('returns past date for date routes so frontend can determine read-only', function () {
    $user = User::factory()->create();
    $yesterday = now()->subDay()->toDateString();

    $response = $this->actingAs($user)->get("/{$yesterday}");

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.date', $yesterday)
    );
});

it('only allows updating today note via PUT', function () {
    $user = User::factory()->create();
    $pastDate = now()->subDay()->toDateString();

    // Create a past note with known content
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => $pastDate,
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'original']]]]],
    ]);

    $newContent = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'tampered']]]]];

    // PUT /note always writes to today, not to the past note
    $this->actingAs($user)->put('/note', ['content' => $newContent]);

    // Past note should be untouched
    $note->refresh();
    expect($note->content['content'][0]['content'][0]['text'])->toBe('original');

    // Today's note should have the new content
    $todayNote = Note::where('user_id', $user->id)->where('date', now()->toDateString())->first();
    expect($todayNote)->not->toBeNull();
    expect($todayNote->content['content'][0]['content'][0]['text'])->toBe('tampered');
});

it('does not allow navigating to dates with invalid format', function () {
    $user = User::factory()->create();

    // Structurally invalid paths are rejected by the route regex
    $this->actingAs($user)->get('/not-a-date')->assertStatus(404);
    $this->actingAs($user)->get('/2026-1-1')->assertStatus(404);
});

// --- Placeholder tests ---

it('provides static placeholder when today note is empty and no AI placeholder exists', function () {
    $user = User::factory()->create();

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday stuff']]]]],
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.content', null)
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "What's on your plate today?"]]]],
        ])
    );
});

it('does not provide placeholder when today note has content', function () {
    $user = User::factory()->create();

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday']]]]],
    ]);

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'today']]]]],
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.content', fn ($content) => $content !== null)
        ->missing('previousContent')
    );
});

it('provides static placeholder even when no previous notes exist', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('previousContent', [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "What's on your plate today?"]]]],
        ])
    );
});

// --- Notes window / preloading tests ---

it('returns a notes window prop with 10 dates when viewing today', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('notes')
        ->where('notes', function ($notes) {
            $notes = json_decode(json_encode($notes), true);
            // Viewing today: daysForward = min(4, 0) = 0, daysBack = 9
            // So window is today minus 9 days through today = 10 dates
            expect(count($notes))->toBe(10);

            $today = now()->toDateString();
            expect(array_key_exists($today, $notes))->toBeTrue();

            $nineAgo = now()->subDays(9)->toDateString();
            expect(array_key_exists($nineAgo, $notes))->toBeTrue();

            return true;
        })
    );
});

it('returns notes window with content for dates that have notes', function () {
    $user = User::factory()->create();
    $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'yesterday']]]]];
    $yesterday = now()->subDay()->toDateString();

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => $yesterday,
        'content' => $content,
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where("notes.{$yesterday}.content", $content)
        ->where("notes.{$yesterday}.id", fn ($id) => $id !== null)
        ->where('notes.'.now()->toDateString().'.content', null)
        ->where('notes.'.now()->toDateString().'.id', null)
    );
});

it('uses adaptive windowing when viewing a past date', function () {
    $user = User::factory()->create();
    $pastDate = now()->subDays(2)->toDateString();

    $response = $this->actingAs($user)->get("/{$pastDate}");

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('notes')
        ->where('notes', function ($notes) use ($pastDate) {
            $notes = json_decode(json_encode($notes), true);
            $keys = array_keys($notes);
            sort($keys);

            // Viewing 2 days ago: daysForward = min(4, 2) = 2, daysBack = 7
            // Window: pastDate - 7 through pastDate + 2 = 10 dates
            expect(count($notes))->toBe(10);

            // The viewed date should be in the window
            expect($keys)->toContain($pastDate);

            // Today (pastDate + 2) should be in the window
            $today = now()->toDateString();
            expect($keys)->toContain($today);

            // 7 days before the viewed date should be in the window
            $sevenBefore = now()->subDays(9)->toDateString();
            expect($keys)->toContain($sevenBefore);

            return true;
        })
    );
});

it('clamps the notes window to not exceed today', function () {
    $user = User::factory()->create();
    $yesterday = now()->subDay()->toDateString();

    $response = $this->actingAs($user)->get("/{$yesterday}");

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notes', function ($notes) {
            $notes = json_decode(json_encode($notes), true);
            // Viewing yesterday: daysForward = min(4, 1) = 1, daysBack = 8
            // Window: yesterday - 8 through yesterday + 1 (= today) = 10 dates
            expect(count($notes))->toBe(10);

            $tomorrow = now()->addDay()->toDateString();
            expect(array_key_exists($tomorrow, $notes))->toBeFalse();

            return true;
        })
    );
});
