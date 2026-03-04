<?php

use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function linkSampleStep(): array
{
    return [
        'stepType' => 'replace',
        'from' => 0,
        'to' => 0,
        'slice' => ['content' => [['type' => 'text', 'text' => 'a']]],
    ];
}

// --- Round-trip tests: save and retrieve link content ---

it('saves content with a markdown-style link mark', function () {
    $user = User::factory()->create();
    $content = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Check out '],
                [
                    'type' => 'text',
                    'text' => 'my site',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'auto' => false]]],
                ],
            ],
        ]],
    ];

    $response = $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [linkSampleStep()],
        'clientID' => 'tab-link-1',
        'doc' => $content,
    ]);
    $response->assertOk();

    $note = Note::where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->content)->toBe($content);
});

it('saves content with a bare URL link mark', function () {
    $user = User::factory()->create();
    $content = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Visit '],
                [
                    'type' => 'text',
                    'text' => 'https://example.com',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'auto' => true]]],
                ],
                ['type' => 'text', 'text' => ' for details'],
            ],
        ]],
    ];

    $response = $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [linkSampleStep()],
        'clientID' => 'tab-link-2',
        'doc' => $content,
    ]);
    $response->assertOk();

    $note = Note::where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->content)->toBe($content);
});

it('retrieves link content via Inertia props', function () {
    $user = User::factory()->create();
    $content = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'click here',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'auto' => false]]],
                ],
            ],
        ]],
    ];

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => $content,
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('note.content', $content)
    );
});

// --- Content with only links should be saved normally ---

it('saves a note containing only a markdown link', function () {
    $user = User::factory()->create();
    $content = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'my link',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'auto' => false]]],
            ]],
        ]],
    ];

    $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [linkSampleStep()],
        'clientID' => 'tab-link-3',
        'doc' => $content,
    ]);

    $note = Note::where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->content)->toBe($content);
});

it('saves a note containing only a bare URL link', function () {
    $user = User::factory()->create();
    $content = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'https://example.com',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'auto' => true]]],
            ]],
        ]],
    ];

    $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [linkSampleStep()],
        'clientID' => 'tab-link-4',
        'doc' => $content,
    ]);

    $note = Note::where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->content)->toBe($content);
});

// --- Preserves mark attributes on update ---

it('preserves link marks when updating an existing note', function () {
    $user = User::factory()->create();

    // Create initial note with plain text
    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]]]],
        'version' => 0,
    ]);

    // Update with linked content
    $newContent = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'hello '],
                [
                    'type' => 'text',
                    'text' => 'world',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://world.com', 'auto' => false]]],
                ],
            ],
        ]],
    ];

    $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [linkSampleStep()],
        'clientID' => 'tab-link-5',
        'doc' => $newContent,
    ]);

    $note = Note::where('user_id', $user->id)->first();
    expect($note->content)->toBe($newContent);
});

it('preserves multiple links in the same paragraph', function () {
    $user = User::factory()->create();
    $content = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'first',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://first.com', 'auto' => false]]],
                ],
                ['type' => 'text', 'text' => ' and '],
                [
                    'type' => 'text',
                    'text' => 'https://second.com',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://second.com', 'auto' => true]]],
                ],
            ],
        ]],
    ];

    $this->actingAs($user)->postJson('/note/steps', [
        'version' => 0,
        'steps' => [linkSampleStep()],
        'clientID' => 'tab-link-6',
        'doc' => $content,
    ]);

    $note = Note::where('user_id', $user->id)->first();
    expect($note)->not->toBeNull();
    expect($note->content)->toBe($content);
});

// --- Previous content with links ---

it('provides static placeholder when previous note has links but no AI placeholder exists', function () {
    $user = User::factory()->create();

    Note::factory()->create([
        'user_id' => $user->id,
        'date' => now()->subDay()->toDateString(),
        'content' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'See '],
                    [
                        'type' => 'text',
                        'text' => 'this link',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'auto' => false]]],
                    ],
                ],
            ]],
        ],
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
