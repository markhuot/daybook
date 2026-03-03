<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Validation tests ---

it('rejects login when email is missing', function () {
    $this->post('/login', [
        'password' => 'secret123',
    ])->assertSessionHasErrors('email');
});

it('rejects login when password is missing', function () {
    $this->post('/login', [
        'email' => 'user@example.com',
    ])->assertSessionHasErrors('password');
});

it('rejects login when email is not a valid email', function () {
    $this->post('/login', [
        'email' => 'not-an-email',
        'password' => 'secret123',
    ])->assertSessionHasErrors('email');
});

it('rejects login with empty payload', function () {
    $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);
});

// --- Authentication tests ---

it('logs in a valid user and redirects to home', function () {
    $user = User::factory()->create([
        'password' => bcrypt('correct-password'),
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('returns validation errors for wrong credentials', function () {
    $user = User::factory()->create([
        'password' => bcrypt('correct-password'),
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('returns json response on successful login when requesting json', function () {
    $user = User::factory()->create([
        'password' => bcrypt('correct-password'),
    ]);

    $response = $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ]);

    $response->assertOk();
    $response->assertJson(['authenticated' => true]);
});

it('returns json 422 on failed login when requesting json', function () {
    $user = User::factory()->create([
        'password' => bcrypt('correct-password'),
    ]);

    $response = $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
});

it('returns json validation errors when fields are missing via json', function () {
    $response = $this->postJson('/login', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email', 'password']);
});

it('supports the remember me option', function () {
    $user = User::factory()->create([
        'password' => bcrypt('correct-password'),
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-password',
        'remember' => true,
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});
