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
1. Find any unchecked tasks (lines with "- [ ]"). Copy those lines exactly.
2. Add a few short follow-up items as task list items using the "- [ ]" format — remind them of plans, ask how something went, nudge next steps.

FORMAT:
- Every line in your response must use the task list format: "- [ ] item text"
- Write like a quick daily agenda. Short items, one line each.
- Do NOT write long paragraphs.
- Do NOT copy or paraphrase the original entry. Only copy unchecked "- [ ]" task lines.
- Do NOT include completed "- [x]" tasks.
- Do NOT include any preamble like "Here's", "Okay", "Sure", or "Tomorrow's entry:".
- Start your response directly with the first "- [ ]" item.
PROMPT;
    }
}
