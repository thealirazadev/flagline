<?php

use App\Models\Environment;
use App\Models\FlagEnvironment;
use App\Models\Ruleset;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('creates an environment and prints its credentials once', function () {
    $this->artisan('app:create-environment', ['name' => 'qa'])
        ->expectsOutputToContain('Environment qa created.')
        ->expectsOutputToContain('SDK key:        fl_sdk_')
        ->expectsOutputToContain('Signing secret: fl_sig_')
        ->assertExitCode(0);

    $qa = Environment::where('name', 'qa')->firstOrFail();

    expect($qa->sdk_key)->toStartWith('fl_sdk_')
        ->and(Ruleset::where('environment_id', $qa->id)->count())->toBe(0);

    $document = json_decode($this->get('/api/v1/ruleset', ['Authorization' => 'Bearer '.$qa->sdk_key])->getContent(), true);

    expect($document)->toMatchArray(['environment' => 'qa', 'version' => 0, 'flags' => []]);
});

it('starts every existing flag off in the new environment and publishes it', function () {
    $this->actingAs(User::factory()->create());
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);
    $this->post('/flags', ['key' => 'pricing-copy', 'name' => 'Pricing', 'type' => 'string', 'variants' => ['short', 'long']]);

    $this->artisan('app:create-environment', ['name' => 'qa'])
        ->expectsOutputToContain('2 existing flag(s) start off in qa.')
        ->assertExitCode(0);

    $qa = Environment::where('name', 'qa')->firstOrFail();
    $states = FlagEnvironment::with(['flag', 'offVariant', 'fallthroughVariant'])
        ->where('environment_id', $qa->id)
        ->get()
        ->keyBy(fn (FlagEnvironment $state) => $state->flag->key);

    expect($states->keys()->sort()->values()->all())->toBe(['checkout-redesign', 'pricing-copy'])
        ->and($states['checkout-redesign']->enabled)->toBeFalse()
        ->and($states['checkout-redesign']->killed)->toBeFalse()
        ->and($states['checkout-redesign']->offVariant->value)->toBe('false')
        ->and($states['checkout-redesign']->fallthroughVariant->value)->toBe('true')
        ->and($states['pricing-copy']->offVariant->value)->toBe('short');

    $this->get('/flags/checkout-redesign/edit?env=qa')->assertOk();

    $document = json_decode($this->get('/api/v1/ruleset', ['Authorization' => 'Bearer '.$qa->sdk_key])->getContent(), true);

    expect($document['version'])->toBe(1)
        ->and(array_keys($document['flags']))->toBe(['checkout-redesign', 'pricing-copy'])
        ->and($document['flags']['checkout-redesign']['enabled'])->toBeFalse();
});

it('rejects an invalid or taken environment name', function (string $name) {
    $this->artisan('app:create-environment', ['name' => $name])->assertExitCode(1);

    expect(Environment::count())->toBe(2);
})->with([
    'taken' => 'production',
    'uppercase' => 'QA',
    'leading dash' => '-qa',
    'too long' => str_repeat('q', 51),
]);
