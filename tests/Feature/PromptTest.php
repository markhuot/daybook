<?php

use App\Jobs\RunPrompt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches RunPrompt job with valid prompt', function () {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/prompt', [
        'prompt' => 'Add a dark mode toggle',
    ]);

    $response->assertRedirect();

    Queue::assertPushed(RunPrompt::class, function (RunPrompt $job) {
        return $job->prompt === 'Add a dark mode toggle';
    });
});

it('rejects empty prompt with validation error', function () {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/prompt', [
        'prompt' => '',
    ]);

    $response->assertSessionHasErrors('prompt');
    Queue::assertNothingPushed();
});

it('rejects prompt over 5000 characters', function () {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/prompt', [
        'prompt' => str_repeat('a', 5001),
    ]);

    $response->assertSessionHasErrors('prompt');
    Queue::assertNothingPushed();
});

it('rejects missing prompt field', function () {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/prompt', []);

    $response->assertSessionHasErrors('prompt');
    Queue::assertNothingPushed();
});

it('redirects guests to login', function () {
    $this->post('/prompt', ['prompt' => 'test'])->assertRedirect('/login');
});

it('constructs the correct process command in RunPrompt job', function () {
    $job = new RunPrompt('Fix the navbar bug');

    expect($job->prompt)->toBe('Fix the navbar bug');
    expect($job->timeout)->toBe(300);
    expect($job->tries)->toBe(1);
});
