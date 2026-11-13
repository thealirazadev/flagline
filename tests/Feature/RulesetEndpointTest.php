<?php

use App\Models\Environment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->production = Environment::where('name', 'production')->firstOrFail();
    $this->auth = ['Authorization' => 'Bearer '.$this->production->sdk_key];
});

function signatureOf(Environment $environment, string $body): string
{
    return 'sha256='.hash_hmac('sha256', $body, $environment->signing_secret);
}

it('serves the latest document with its etag and a signature over the exact bytes', function () {
    $this->actingAs(User::factory()->create())
        ->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);

    $response = $this->get('/api/v1/ruleset', $this->auth)->assertOk()
        ->assertHeader('Cache-Control', 'no-cache, private')
        ->assertHeader('Content-Type', 'application/json');
    $body = $response->getContent();

    expect($response->headers->get('X-Flagline-Signature'))->toBe(signatureOf($this->production, $body))
        ->and($response->headers->get('ETag'))->toMatch('/^"1-[0-9a-f]{16}"$/')
        ->and(json_decode($body, true)['flags'])->toHaveKey('checkout-redesign');
});

it('answers 304 with an empty body when the etag still matches', function () {
    $etag = $this->get('/api/v1/ruleset', $this->auth)->headers->get('ETag');

    $this->get('/api/v1/ruleset', $this->auth + ['If-None-Match' => $etag])
        ->assertStatus(304)->assertHeader('ETag', $etag)->assertContent('');
});

it('serves a stable signed version 0 document for a fresh environment', function () {
    $first = $this->get('/api/v1/ruleset', $this->auth);
    $second = $this->get('/api/v1/ruleset', $this->auth);

    expect(json_decode($first->getContent(), true))->toMatchArray(['version' => 0, 'flags' => []])
        ->and($first->getContent())->toBe($second->getContent())
        ->and($first->headers->get('ETag'))->toBe($second->headers->get('ETag'))
        ->and($first->headers->get('X-Flagline-Signature'))->toBe(signatureOf($this->production, $first->getContent()));
});

it('sees a dashboard change on the very next fetch', function () {
    $before = $this->get('/api/v1/ruleset', $this->auth)->headers->get('ETag');
    $this->actingAs(User::factory()->create())
        ->post('/flags', ['key' => 'new-pricing', 'name' => 'Pricing', 'type' => 'boolean']);

    $after = $this->get('/api/v1/ruleset', $this->auth + ['If-None-Match' => $before])->assertOk();
    expect(json_decode($after->getContent(), true)['flags'])->toHaveKey('new-pricing');
});

it('answers with the error envelope for bad keys, methods, and paths', function (string $method, string $uri, array $headers, int $status, string $code) {
    $this->json($method, $uri, [], $headers)
        ->assertStatus($status)
        ->assertExactJson(['error' => ['code' => $code, 'message' => match ($code) {
            'unauthorized' => 'The SDK key is missing or not recognized.',
            'method_not_allowed' => 'The ruleset is read with GET.',
            'not_found' => 'There is no API route at this path.',
        }]]);
})->with([
    'missing key' => ['GET', '/api/v1/ruleset', [], 401, 'unauthorized'],
    'unknown key' => ['GET', '/api/v1/ruleset', ['Authorization' => 'Bearer fl_sdk_unknown'], 401, 'unauthorized'],
    'not a bearer' => ['GET', '/api/v1/ruleset', ['Authorization' => 'Basic abc'], 401, 'unauthorized'],
    'wrong method' => ['POST', '/api/v1/ruleset', [], 405, 'method_not_allowed'],
    'other path' => ['GET', '/api/v1/flags', [], 404, 'not_found'],
]);

it('rate limits per key without affecting other keys', function () {
    config(['flagline.sdk_rate_limit' => 3]);
    $staging = Environment::where('name', 'staging')->firstOrFail();

    foreach (range(1, 3) as $i) {
        $this->get('/api/v1/ruleset', $this->auth)->assertOk();
    }
    $this->get('/api/v1/ruleset', $this->auth)
        ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

    $this->get('/api/v1/ruleset', ['Authorization' => 'Bearer '.$staging->sdk_key])->assertOk();
});
