<?php

namespace Tests;

use App\Ai\Agents\WeeklySummaryAgent;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Ai\Embeddings;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Prevent all tests from hitting real Ollama when summary jobs
        // run synchronously (QUEUE_CONNECTION=sync in phpunit.xml).
        WeeklySummaryAgent::fake();

        // Prevent all tests from hitting real embedding APIs.
        Embeddings::fake();
    }
}
