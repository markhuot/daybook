<?php

use App\Ai\Agents\MonthlySummaryAgent;
use App\Ai\Agents\WeeklySummaryAgent;

it('has a weekly summary agent with instructions', function () {
    $agent = new WeeklySummaryAgent;

    expect($agent->instructions())
        ->toContain('past 7 days')
        ->toContain('Suggested goals');
});

it('has a monthly summary agent with instructions', function () {
    $agent = new MonthlySummaryAgent;

    expect($agent->instructions())
        ->toContain('past 30 days')
        ->toContain('Suggested goals');
});

it('can prompt the weekly summary agent', function () {
    WeeklySummaryAgent::fake(['Your week was productive.']);

    $response = (new WeeklySummaryAgent)->prompt('Some journal entries...');

    expect((string) $response)->toBe('Your week was productive.');
    WeeklySummaryAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Some journal entries'));
});

it('can prompt the monthly summary agent', function () {
    MonthlySummaryAgent::fake(['Your month was great.']);

    $response = (new MonthlySummaryAgent)->prompt('Some journal entries...');

    expect((string) $response)->toBe('Your month was great.');
    MonthlySummaryAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Some journal entries'));
});
