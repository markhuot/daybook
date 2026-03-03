<?php

namespace App\Jobs;

use App\Models\Note;
use Carbon\Carbon;
use Illuminate\Support\Collection;

trait ExtractsNoteText
{
    /**
     * Convert a ProseMirror document node into markdown text.
     */
    protected function extractText(array $node): string
    {
        return trim($this->nodeToMarkdown($node, 0));
    }

    /**
     * Convert a single ProseMirror node to its markdown representation.
     */
    protected function nodeToMarkdown(array $node, int $depth): string
    {
        $type = $node['type'] ?? '';

        return match ($type) {
            'doc' => $this->blocksToMarkdown($node['content'] ?? [], $depth),
            'heading' => '# '.$this->inlineToMarkdown($node)."\n",
            'paragraph' => $this->inlineToMarkdown($node)."\n",
            'bullet_list' => $this->listToMarkdown($node, 'bullet', $depth),
            'ordered_list' => $this->listToMarkdown($node, 'ordered', $depth),
            'task_list' => $this->listToMarkdown($node, 'task', $depth),
            default => $this->inlineToMarkdown($node)."\n",
        };
    }

    /**
     * Join block-level children with blank lines between them.
     */
    protected function blocksToMarkdown(array $blocks, int $depth): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            $parts[] = $this->nodeToMarkdown($block, $depth);
        }

        return implode("\n", $parts);
    }

    /**
     * Render a list (bullet, ordered, or task) with proper prefixes and nesting.
     */
    protected function listToMarkdown(array $node, string $style, int $depth): string
    {
        $items = $node['content'] ?? [];
        $indent = str_repeat('  ', $depth);
        $result = '';
        $startOrder = $node['attrs']['order'] ?? 1;

        foreach ($items as $i => $item) {
            $prefix = match ($style) {
                'ordered' => ($startOrder + $i).'. ',
                'task' => ($item['attrs']['checked'] ?? false) ? '- [x] ' : '- [ ] ',
                default => '- ',
            };

            $suffix = '';
            if ($style === 'task') {
                $seconds = $item['attrs']['timerSeconds'] ?? 0;
                if ($seconds > 0) {
                    $suffix = ' (took '.$this->formatDuration($seconds).')';
                }
            }

            $itemBlocks = $item['content'] ?? [];

            foreach ($itemBlocks as $j => $block) {
                if ($j === 0) {
                    // First block gets the bullet/number prefix and optional timer suffix
                    $result .= $indent.$prefix.trim($this->nodeToMarkdown($block, 0)).$suffix."\n";
                } else {
                    // Subsequent blocks (e.g. nested lists) are indented under the item
                    $result .= $this->nodeToMarkdown($block, $depth + 1);
                }
            }
        }

        return $result;
    }

    /**
     * Render inline content (text nodes with marks) to markdown.
     */
    protected function inlineToMarkdown(array $node): string
    {
        if (isset($node['text'])) {
            $text = $node['text'];

            foreach ($node['marks'] ?? [] as $mark) {
                $text = match ($mark['type'] ?? '') {
                    'bold' => '**'.$text.'**',
                    'italic' => '*'.$text.'*',
                    'link' => '['.$text.']('.($mark['attrs']['href'] ?? '').')',
                    default => $text,
                };
            }

            return $text;
        }

        $result = '';

        foreach ($node['content'] ?? [] as $child) {
            $result .= $this->inlineToMarkdown($child);
        }

        return $result;
    }

    /**
     * Format a number of seconds into a human-readable duration like "3h 24m".
     */
    protected function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0 && $minutes > 0) {
            return "{$hours}h {$minutes}m";
        }

        if ($hours > 0) {
            return "{$hours}h";
        }

        if ($minutes > 0) {
            return "{$minutes}m";
        }

        return '<1m';
    }

    /**
     * @param  Collection<int, Note>  $notes
     */
    protected function formatNotes(Collection $notes): string
    {
        return $notes->map(function (Note $note) {
            $date = Carbon::parse($note->date)->format('l, F j');
            $text = trim($this->extractText($note->content));

            return "## {$date}\n\n{$text}";
        })->implode("\n\n");
    }
}
