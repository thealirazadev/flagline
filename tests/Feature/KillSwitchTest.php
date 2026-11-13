<?php

use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::factory()->create());
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);
    $this->production = Environment::where('name', 'production')->firstOrFail();
});

function served(Environment $environment): array
{
    $response = test()->get('/api/v1/ruleset', ['Authorization' => 'Bearer '.$environment->sdk_key]);

    return json_decode($response->getContent(), true);
}

it('kills a flag synchronously and the next fetch sees it', function () {
    $this->post('/flags/checkout-redesign/environments/production/kill')
        ->assertSessionHas('status');

    $document = served($this->production);
    $audit = AuditLog::where('action', 'flag.killed')->sole();

    expect($document['flags']['checkout-redesign']['killed'])->toBeTrue()
        ->and($audit->ruleset_version)->toBe($document['version'])
        ->and(served(Environment::where('name', 'staging')->firstOrFail())['flags']['checkout-redesign']['killed'])->toBeFalse();
});

it('restores a killed flag and audits both actions', function () {
    $this->post('/flags/checkout-redesign/environments/production/kill');
    $this->post('/flags/checkout-redesign/environments/production/restore');

    expect(served($this->production)['flags']['checkout-redesign']['killed'])->toBeFalse()
        ->and(AuditLog::where('action', 'flag.restored')->value('ruleset_version'))->toBe(served($this->production)['version']);
});

it('shows the kill banner and restore button on the edit screen', function () {
    $this->post('/flags/checkout-redesign/environments/production/kill');

    $this->get('/flags/checkout-redesign/edit')->assertOk()
        ->assertSee('Killed in production')->assertSee('Restore');
    $this->get('/flags')->assertOk()->assertSee('is-killed', false);
});

it('does not publish when the flag is already killed', function () {
    $this->post('/flags/checkout-redesign/environments/production/kill');
    $version = served($this->production)['version'];

    $this->post('/flags/checkout-redesign/environments/production/kill')->assertSessionHas('status', 'Already killed.');
    expect(served($this->production)['version'])->toBe($version);
});
