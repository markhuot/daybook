<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class WeeklySummaryAgent implements Agent
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
You are a thoughtful assistant for a personal daily journal app. The user will provide their journal entries from the past 7 days.

Write a response in markdown with two sections:

1. A brief 2-3 sentence summary of their week — what they worked on, key themes, and noteworthy events. Write in second person ("You..."). Be specific and reference actual things they mentioned.

2. Under a "## Suggested goals" heading, offer 2-3 gentle, actionable suggestions for the coming days. These can relate to productivity, mental health, physical health, or personal wellbeing. Base them on what the user actually wrote — if they mentioned feeling stressed, suggest rest; if they completed a big project, suggest celebration or a break. Do not be preachy or generic. Use a markdown bullet list.

Keep the total response under 150 words.
PROMPT;
    }
}
