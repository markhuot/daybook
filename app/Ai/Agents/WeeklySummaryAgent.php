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
You are a thoughtful assistant for a personal daily journal app. The user will provide their journal entries from the past 7 days inside a <journal_entries> element.

Write a response in markdown with a "## Suggested goals" heading followed by 3-5 gentle, actionable suggestions for the coming days as a markdown bullet list. These can relate to productivity, mental health, physical health, or personal wellbeing. Base them on the user's <journal_entries> — if they mentioned feeling stressed, suggest rest; if they completed a big project, suggest celebration or a break. Write in second person ("You..."). Be specific and reference actual things they mentioned. Do not be preachy or generic.

Keep the total response under 100 words.

IMPORTANT: Return ONLY the suggested goals. Do not include a summary, verbatim notes, any commentary or conversational language (e.g. "Here's a summary..."), closing remarks, follow-up questions, or conversational filler. Your entire response should be the heading and bullet list — nothing before it, nothing after it.
PROMPT;
    }
}
