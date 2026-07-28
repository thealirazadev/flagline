<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('operator@example.com|127.0.0.1');

    $this->operator = User::factory()->create([
        'email' => 'operator@example.com',
        'password' => Hash::make('correct-horse-battery'),
    ]);
});

it('shows the login form to guests', function () {
    $this->get('/login')->assertOk()->assertSee('Log in');
});

it('logs an operator in and lands on the flag index', function () {
    $this->post('/login', ['email' => 'operator@example.com', 'password' => 'correct-horse-battery'])
        ->assertRedirect('/flags');

    $this->assertAuthenticatedAs($this->operator);
});

it('rejects a wrong password with a safe error and no session', function () {
    $response = $this->post('/login', ['email' => 'operator@example.com', 'password' => 'wrong-password']);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toBe('Those credentials do not match our records.');
    $this->assertGuest();
});

it('validates the login form', function () {
    $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);
    $this->assertGuest();
});

it('throttles repeated failures for the same email and ip', function () {
    foreach (range(1, 5) as $ignored) {
        $this->post('/login', ['email' => 'operator@example.com', 'password' => 'wrong-password']);
    }

    $this->post('/login', ['email' => 'operator@example.com', 'password' => 'correct-horse-battery'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain('Too many login attempts');
    $this->assertGuest();
});

it('logs out and clears the session', function () {
    $this->actingAs($this->operator)->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});

it('sends guests on dashboard routes to the login page', function (string $path) {
    $this->get($path)->assertRedirect('/login');
})->with(['/flags', '/flags/create', '/environments', '/audit']);

it('exposes no registration route', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

it('sends the root path to the flag index', function () {
    $this->actingAs($this->operator)->get('/')->assertRedirect('/flags');
});
