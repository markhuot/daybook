<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class NotePlaceholderAgent implements Agent
{
    use Promptable;

    public function provider(): string
    {
        return config('summaries.provider');
    }

    public function model(): string
    {
        return config('summaries.model');
    }

    public function timeout(): int
    {
        return config('summaries.timeout');
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You help with a daily journal app. The user sends you today's journal entry. You write a short starting point for TOMORROW's entry.

INSTRUCTIONS:
1. Reproduce the heading structure from today's entry. If the entry used headings (e.g. "## Work", "## Personal", "## Health"), include those same headings in the same order to preserve the user's daily note structure.
2. Under each heading (or at the top if there are no headings), find any unchecked tasks (lines with "- [ ]") that belonged to that section and copy those lines exactly.
3. Add a few short follow-up items as task list items using the "- [ ]" format — remind them of plans, ask how something went, nudge next steps. Place these under the most relevant heading.

FORMAT:
- Preserve the user's heading hierarchy exactly as it appeared (e.g. "## Work", "## Personal"). Do not rename, reorder, or add new headings.
- Task items must use the task list format: "- [ ] item text"
- Write like a quick daily agenda. Short items, one line each.
- Do NOT write long paragraphs.
- Do NOT copy or paraphrase the original entry's prose. Only copy unchecked "- [ ]" task lines and headings.
- Do NOT include completed "- [x]" tasks.
- Do NOT include any preamble like "Here's", "Okay", "Sure", or "Tomorrow's entry:".
- If there are no headings in the original entry, start your response directly with the first "- [ ]" item.
PROMPT;
    }
}
