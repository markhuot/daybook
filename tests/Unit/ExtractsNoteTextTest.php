<?php

use App\Jobs\ExtractsNoteText;

function extractor(): object
{
    return new class
    {
        use ExtractsNoteText;

        public function convert(array $node): string
        {
            return $this->extractText($node);
        }
    };
}

function doc(array $blocks): array
{
    return ['type' => 'doc', 'content' => $blocks];
}

function paragraph(array|string $content): array
{
    if (is_string($content)) {
        $content = [['type' => 'text', 'text' => $content]];
    }

    return ['type' => 'paragraph', 'content' => $content];
}

// --- Headings ---

it('renders headings with a markdown heading marker', function () {
    $result = extractor()->convert(doc([
        ['type' => 'heading', 'content' => [['type' => 'text', 'text' => 'My Heading']]],
    ]));

    expect($result)->toBe('# My Heading');
});

it('renders headings with inline marks', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'heading',
            'content' => [
                ['type' => 'text', 'text' => 'Bold '],
                ['type' => 'text', 'text' => 'heading', 'marks' => [['type' => 'bold']]],
            ],
        ],
    ]));

    expect($result)->toBe('# Bold **heading**');
});

// --- Paragraphs ---

it('separates paragraphs with blank lines', function () {
    $result = extractor()->convert(doc([
        paragraph('First paragraph.'),
        paragraph('Second paragraph.'),
    ]));

    expect($result)->toBe("First paragraph.\n\nSecond paragraph.");
});

// --- Bullet lists ---

it('renders bullet list items with dash markers', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'bullet_list',
            'content' => [
                ['type' => 'list_item', 'content' => [paragraph('Item one')]],
                ['type' => 'list_item', 'content' => [paragraph('Item two')]],
                ['type' => 'list_item', 'content' => [paragraph('Item three')]],
            ],
        ],
    ]));

    expect($result)->toBe("- Item one\n- Item two\n- Item three");
});

// --- Ordered lists ---

it('renders ordered list items with number markers', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'ordered_list',
            'attrs' => ['order' => 1],
            'content' => [
                ['type' => 'list_item', 'content' => [paragraph('First')]],
                ['type' => 'list_item', 'content' => [paragraph('Second')]],
                ['type' => 'list_item', 'content' => [paragraph('Third')]],
            ],
        ],
    ]));

    expect($result)->toBe("1. First\n2. Second\n3. Third");
});

it('respects the start order attribute on ordered lists', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'ordered_list',
            'attrs' => ['order' => 5],
            'content' => [
                ['type' => 'list_item', 'content' => [paragraph('Fifth')]],
                ['type' => 'list_item', 'content' => [paragraph('Sixth')]],
            ],
        ],
    ]));

    expect($result)->toBe("5. Fifth\n6. Sixth");
});

// --- Nested lists ---

it('indents nested lists', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'bullet_list',
            'content' => [
                [
                    'type' => 'list_item',
                    'content' => [
                        paragraph('Parent'),
                        [
                            'type' => 'bullet_list',
                            'content' => [
                                ['type' => 'list_item', 'content' => [paragraph('Child')]],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]));

    expect($result)->toBe("- Parent\n  - Child");
});

// --- Links ---

it('renders links as markdown links', function () {
    $result = extractor()->convert(doc([
        paragraph([
            ['type' => 'text', 'text' => 'Visit '],
            [
                'type' => 'text',
                'text' => 'Laravel',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://laravel.com']]],
            ],
            ['type' => 'text', 'text' => ' for docs.'],
        ]),
    ]));

    expect($result)->toBe('Visit [Laravel](https://laravel.com) for docs.');
});

it('renders bold and italic marks', function () {
    $result = extractor()->convert(doc([
        paragraph([
            ['type' => 'text', 'text' => 'This is '],
            ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' and '],
            ['type' => 'text', 'text' => 'italic', 'marks' => [['type' => 'italic']]],
        ]),
    ]));

    expect($result)->toBe('This is **bold** and *italic*');
});

// --- Task lists ---

it('renders unchecked task list items with empty checkbox markers', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'task_list',
            'content' => [
                ['type' => 'task_list_item', 'attrs' => ['checked' => false], 'content' => [paragraph('Pending task')]],
            ],
        ],
    ]));

    expect($result)->toBe('- [ ] Pending task');
});

it('renders checked task list items with checked checkbox markers', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'task_list',
            'content' => [
                ['type' => 'task_list_item', 'attrs' => ['checked' => true], 'content' => [paragraph('Done task')]],
            ],
        ],
    ]));

    expect($result)->toBe('- [x] Done task');
});

it('renders mixed checked and unchecked task list items', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'task_list',
            'content' => [
                ['type' => 'task_list_item', 'attrs' => ['checked' => true], 'content' => [paragraph('Buy groceries')]],
                ['type' => 'task_list_item', 'attrs' => ['checked' => false], 'content' => [paragraph('Clean house')]],
                ['type' => 'task_list_item', 'attrs' => ['checked' => true], 'content' => [paragraph('Walk the dog')]],
            ],
        ],
    ]));

    expect($result)->toBe("- [x] Buy groceries\n- [ ] Clean house\n- [x] Walk the dog");
});

// --- Task list timer durations ---

it('appends time spent to task list items with tracked time', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'task_list',
            'content' => [
                [
                    'type' => 'task_list_item',
                    'attrs' => ['checked' => true, 'timerSeconds' => (3 * 3600) + (24 * 60)],
                    'content' => [paragraph('Finished something')],
                ],
            ],
        ],
    ]));

    expect($result)->toBe('- [x] Finished something (took 3h 24m)');
});

it('formats duration as hours only when minutes are zero', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'task_list',
            'content' => [
                [
                    'type' => 'task_list_item',
                    'attrs' => ['checked' => true, 'timerSeconds' => 7200],
                    'content' => [paragraph('Two hour task')],
                ],
            ],
        ],
    ]));

    expect($result)->toBe('- [x] Two hour task (took 2h)');
});

it('formats duration as minutes only when under an hour', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'task_list',
            'content' => [
                [
                    'type' => 'task_list_item',
                    'attrs' => ['checked' => false, 'timerSeconds' => 1800],
                    'content' => [paragraph('Half hour task')],
                ],
            ],
        ],
    ]));

    expect($result)->toBe('- [ ] Half hour task (took 30m)');
});

it('formats duration as less than one minute for very short times', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'task_list',
            'content' => [
                [
                    'type' => 'task_list_item',
                    'attrs' => ['checked' => true, 'timerSeconds' => 45],
                    'content' => [paragraph('Quick task')],
                ],
            ],
        ],
    ]));

    expect($result)->toBe('- [x] Quick task (took <1m)');
});

it('does not append time when timer seconds is zero', function () {
    $result = extractor()->convert(doc([
        [
            'type' => 'task_list',
            'content' => [
                [
                    'type' => 'task_list_item',
                    'attrs' => ['checked' => true, 'timerSeconds' => 0],
                    'content' => [paragraph('No time tracked')],
                ],
            ],
        ],
    ]));

    expect($result)->toBe('- [x] No time tracked');
});

// --- Empty content ---

it('handles an empty document gracefully', function () {
    $result = extractor()->convert(doc([
        ['type' => 'paragraph'],
    ]));

    expect($result)->toBe('');
});
