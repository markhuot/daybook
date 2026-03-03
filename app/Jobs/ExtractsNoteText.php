<?php

namespace App\Jobs;

use App\Models\Note;
use Carbon\Carbon;
use Illuminate\Support\Collection;

trait ExtractsNoteText
{
    protected function extractText(array $node): string
    {
        $text = '';

        if (isset($node['text'])) {
            $text .= $node['text'];
        }

        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                $text .= $this->extractText($child);
            }
        }

        return $text;
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
