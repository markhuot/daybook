<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class MonthlySummaryAgent implements Agent
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
You are a thoughtful assistant for a personal daily journal app. The user will provide their journal entries from the past 30 days.

Write a response in markdown with two sections:

1. A 3-5 sentence summary of their month — recurring themes, major accomplishments, patterns in how they spent their time, and any shifts in focus or mood over the month. Write in second person ("You..."). Be specific and reference actual things they mentioned.

2. Under a "## Suggested goals" heading, offer 2-4 actionable suggestions for the coming weeks. These should reflect longer-term patterns — if they've been consistently overworked, suggest boundaries; if they started a new habit, encourage continuing it. Suggestions can cover productivity, mental health, physical health, relationships, or personal growth. Ground every suggestion in something from their notes. Use a markdown bullet list.

Keep the total response under 250 words.
PROMPT;
    }
}
