<?php

use App\Events\NoteStepsAccepted;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Flush the entire Redis test database to avoid prefix issues.
    // Laravel adds a prefix (e.g. "laravel-database-") to all Redis keys,
    // but keys()/del() double-prefix, so flushdb is the simplest fix.
    Redis::connection()->flushdb();
});

function sampleDoc(string $text = 'hello'): array
{
    return [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ],
    ];
}

function sampleStep(): array
{
    return [
        'stepType' => 'replace',
        'from' => 0,
        'to' => 0,
        'slice' => ['content' => [['type' => 'text', 'text' => 'a']]],
    ];
}

// --- POST /note/steps ---

it('accepts steps at the correct version and updates the database', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => sampleDoc('original'),
        'version' => 0,
    ]);

    $newDoc = sampleDoc('updated');
    $steps = [sampleStep()];

    $response = $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => $steps,
        'clientID' => 'tab-123',
        'doc' => $newDoc,
    ]);

    $response->assertOk();
    $response->assertJson(['version' => 1]);

    $note->refresh();
    expect($note->version)->toBe(1);
    expect($note->content)->toBe($newDoc);
});

it('stores steps in Redis sorted set', function () {
    $user = User::factory()->create();
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => sampleDoc(),
        'version' => 0,
    ]);

    $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [sampleStep(), sampleStep()],
        'clientID' => 'tab-abc',
        'doc' => sampleDoc('new'),
    ]);

    $redisKey = "note_steps:{$user->id}:".now()->toDateString();
    $members = Redis::zrangebyscore($redisKey, '-inf', '+inf');

    expect($members)->toHaveCount(2);

    $first = json_decode($members[0], true);
    expect($first['version'])->toBe(1);
    expect($first['clientID'])->toBe('tab-abc');

    $second = json_decode($members[1], true);
    expect($second['version'])->toBe(2);
});

it('broadcasts NoteStepsAccepted event on step acceptance', function () {
    Event::fake([NoteStepsAccepted::class]);

    $user = User::factory()->create();
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => sampleDoc(),
        'version' => 0,
    ]);

    $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [sampleStep()],
        'clientID' => 'tab-xyz',
        'doc' => sampleDoc('new'),
    ]);

    Event::assertDispatched(NoteStepsAccepted::class, function ($event) {
        return $event->version === 1
            && count($event->steps) === 1
            && $event->clientIDs === ['tab-xyz'];
    });
});

it('returns 409 with missing steps on version mismatch', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => sampleDoc(),
        'version' => 0,
    ]);

    // First, submit steps to get to version 2
    $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [sampleStep(), sampleStep()],
        'clientID' => 'tab-A',
        'doc' => sampleDoc('v2'),
    ]);

    // Now try to submit at stale version 0
    $response = $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [sampleStep()],
        'clientID' => 'tab-B',
        'doc' => sampleDoc('conflict'),
    ]);

    $response->assertStatus(409);
    $data = $response->json();
    expect($data['version'])->toBe(2);
    expect($data['steps'])->toHaveCount(2);
    expect($data['clientIDs'])->toHaveCount(2);
});

it('creates a new note when none exists and steps are submitted', function () {
    $user = User::factory()->create();

    $doc = sampleDoc('first');

    $response = $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [sampleStep()],
        'clientID' => 'tab-new',
        'doc' => $doc,
    ]);

    $response->assertOk();
    $response->assertJson(['version' => 1]);

    $note = Note::where('user_id', $user->id)->where('date', now()->toDateString())->first();
    expect($note)->not->toBeNull();
    expect($note->content)->toBe($doc);
    expect($note->version)->toBe(1);
});

it('increments version by the number of steps in the batch', function () {
    $user = User::factory()->create();
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => sampleDoc(),
        'version' => 5,
    ]);

    $response = $this->actingAs($user)->postJson('/note/steps', [
        'version' => 5,
        'steps' => [sampleStep(), sampleStep(), sampleStep()],
        'clientID' => 'tab-multi',
        'doc' => sampleDoc('batch'),
    ]);

    $response->assertOk();
    $response->assertJson(['version' => 8]);
});

it('validates required fields for step submission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/note/steps', [])->assertStatus(422);
    $this->actingAs($user)->postJson('/note/steps', ['version' => 0])->assertStatus(422);
    $this->actingAs($user)->postJson('/note/steps', ['version' => 0, 'steps' => []])->assertStatus(422);
});

it('requires authentication for step endpoints', function () {
    $this->postJson('/note/steps', [
        'version' => 0,
        'steps' => [sampleStep()],
        'clientID' => 'tab-anon',
        'doc' => sampleDoc(),
    ])->assertUnauthorized();

    $this->getJson('/note/steps?since=0')->assertUnauthorized();
});

// --- GET /note/steps (catch-up) ---

it('returns steps since a given version', function () {
    $user = User::factory()->create();
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => sampleDoc(),
        'version' => 0,
    ]);

    // Submit a batch of steps
    $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [sampleStep(), sampleStep(), sampleStep()],
        'clientID' => 'tab-catchup',
        'doc' => sampleDoc('v3'),
    ]);

    // Catch up from version 1
    $response = $this->actingAs($user)->getJson('/note/steps?since=1');

    $response->assertOk();
    $data = $response->json();
    expect($data['version'])->toBe(3);
    expect($data['steps'])->toHaveCount(2); // versions 2 and 3
    expect($data['clientIDs'])->toHaveCount(2);
});

it('returns empty steps when client is up to date', function () {
    $user = User::factory()->create();
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => sampleDoc(),
        'version' => 3,
    ]);

    // No steps in Redis, client already at version 3
    $response = $this->actingAs($user)->getJson('/note/steps?since=3');

    $response->assertOk();
    expect($response->json('steps'))->toBe([]);
});

it('returns version 0 when no note exists', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/note/steps?since=0');

    $response->assertOk();
    expect($response->json('version'))->toBe(0);
    expect($response->json('steps'))->toBe([]);
});

// --- Channel authorization ---

it('allows the note owner to subscribe to the note channel', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => sampleDoc(),
    ]);

    $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
        'channel_name' => "private-note.{$note->id}",
        'socket_id' => '123456.654321',
    ]);

    $response->assertOk();
});

it('denies a different user from subscribing to a note channel', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $note = Note::factory()->create([
        'user_id' => $owner->id,
        'date' => now()->toDateString(),
        'content' => sampleDoc(),
    ]);

    $response = $this->actingAs($other)->postJson('/broadcasting/auth', [
        'channel_name' => "private-note.{$note->id}",
    ]);

    $response->assertForbidden();
});

it('denies subscription to a nonexistent note channel', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
        'channel_name' => 'private-note.99999',
    ]);

    $response->assertForbidden();
});

// --- NoteShowController version prop ---

it('includes version in the note Inertia prop', function () {
    $user = User::factory()->create();
    $note = Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => sampleDoc(),
        'version' => 7,
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn ($page) => $page
        ->where('note.version', 7)
    );
});

it('preserves whitespace-only text in steps through the server round-trip', function () {
    Event::fake([NoteStepsAccepted::class]);

    $user = User::factory()->create();
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => sampleDoc('123'),
        'version' => 0,
    ]);

    // A step that inserts a single space — this is what ProseMirror generates
    // when you type a space character. TrimStrings + ConvertEmptyStringsToNull
    // must not strip it.
    $spaceStep = [
        'stepType' => 'replace',
        'from' => 4,
        'to' => 4,
        'slice' => ['content' => [['type' => 'text', 'text' => ' ']]],
    ];

    $response = $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [$spaceStep],
        'clientID' => 'tab-space',
        'doc' => sampleDoc('123 '),
    ]);

    $response->assertOk();

    // Verify the broadcast event preserved the space in the step
    Event::assertDispatched(NoteStepsAccepted::class, function ($event) {
        $stepText = $event->steps[0]['slice']['content'][0]['text'] ?? null;

        return $stepText === ' ';
    });

    // Verify Redis also preserved the space
    $redisKey = "note_steps:{$user->id}:".now()->toDateString();
    $members = Redis::zrangebyscore($redisKey, '-inf', '+inf');
    $stored = json_decode($members[0], true);
    $storedText = $stored['step']['slice']['content'][0]['text'] ?? null;
    expect($storedText)->toBe(' ');

    // Verify the stored document preserved the space
    $note = Note::where('user_id', $user->id)->where('date', now()->toDateString())->first();
    $docText = $note->content['content'][0]['content'][0]['text'] ?? null;
    expect($docText)->toBe('123 ');
});

it('returns version 0 when no note exists in Inertia props', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn ($page) => $page
        ->where('note.version', 0)
    );
});
