<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Validation tests ---

it('rejects registration when name is missing', function () {
    $this->post('/register', [
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('name');
});

it('rejects registration when email is missing', function () {
    $this->post('/register', [
        'name' => 'New User',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');
});

it('rejects registration when email is not valid', function () {
    $this->post('/register', [
        'name' => 'New User',
        'email' => 'not-valid',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');
});

it('rejects registration when email is already taken', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', [
        'name' => 'New User',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');
});

it('rejects registration when password is missing', function () {
    $this->post('/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
    ])->assertSessionHasErrors('password');
});

it('rejects registration when password confirmation does not match', function () {
    $this->post('/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'different456',
    ])->assertSessionHasErrors('password');
});

it('rejects registration when name exceeds 255 characters', function () {
    $this->post('/register', [
        'name' => str_repeat('a', 256),
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('name');
});

it('rejects registration when email exceeds 255 characters', function () {
    $this->post('/register', [
        'name' => 'New User',
        'email' => str_repeat('a', 247).'@test.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');
});

it('rejects registration with empty payload', function () {
    $this->post('/register', [])->assertSessionHasErrors(['name', 'email', 'password']);
});

// --- Successful registration tests ---

it('registers a new user and redirects to home', function () {
    $response = $this->post('/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertRedirect('/');

    $this->assertDatabaseHas('users', [
        'name' => 'New User',
        'email' => 'new@example.com',
    ]);

    $this->assertAuthenticated();
});

it('logs the user in after registration', function () {
    $this->post('/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $user = User::where('email', 'new@example.com')->first();
    $this->assertAuthenticatedAs($user);
});

it('hashes the password on registration', function () {
    $this->post('/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $user = User::where('email', 'new@example.com')->first();
    expect($user->password)->not->toBe('password123');
    expect(password_verify('password123', $user->password))->toBeTrue();
});
