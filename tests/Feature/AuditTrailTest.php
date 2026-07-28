<?php

use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\Flag;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->operator = User::factory()->create();
    $this->actingAs($this->operator);
});

it('records one row for a flag creation with the actor and an after snapshot', function () {
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);

    expect(AuditLog::count())->toBe(1);

    $entry = AuditLog::latest('id')->firstOrFail();

    expect($entry->action)->toBe('flag.created')
        ->and($entry->user_id)->toBe($this->operator->id)
        ->and($entry->before)->toBeNull()
        ->and($entry->after['key'])->toBe('checkout-redesign')
        ->and($entry->after['variants'])->toBe(['true', 'false'])
        ->and($entry->environment_id)->toBeNull();
});

it('records a before and after snapshot for a flag update', function () {
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Old', 'type' => 'boolean']);
    $this->put('/flags/checkout-redesign', ['name' => 'New']);

    expect(AuditLog::count())->toBe(2);

    $entry = AuditLog::latest('id')->firstOrFail();

    expect($entry->action)->toBe('flag.updated')
        ->and($entry->before['name'])->toBe('Old')
        ->and($entry->after['name'])->toBe('New');
});

it('records the environment on a per environment state change', function () {
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);
    $flag = Flag::where('key', 'checkout-redesign')->firstOrFail();

    $this->put('/flags/checkout-redesign/environments/staging', [
        'enabled' => '1',
        'off_variant_id' => $flag->variants->firstWhere('value', 'false')->id,
        'fallthrough_variant_id' => $flag->variants->firstWhere('value', 'true')->id,
    ]);

    $entry = AuditLog::latest('id')->firstOrFail();

    expect(AuditLog::count())->toBe(2)
        ->and($entry->action)->toBe('environment.state_changed')
        ->and($entry->environment->name)->toBe('staging')
        ->and($entry->before['enabled'])->toBeFalse()
        ->and($entry->after['enabled'])->toBeTrue();
});

it('records an archive with a null after snapshot', function () {
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);
    $this->followingRedirects()->post('/flags/checkout-redesign/archive');

    $entry = AuditLog::latest('id')->firstOrFail();

    expect(AuditLog::count())->toBe(2)
        ->and($entry->action)->toBe('flag.archived')
        ->and($entry->before['key'])->toBe('checkout-redesign')
        ->and($entry->after)->toBeNull();
});

it('writes no audit row when the mutation is rejected', function () {
    $payload = ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean'];

    $this->post('/flags', $payload);
    $this->post('/flags', $payload);

    expect(AuditLog::count())->toBe(1);
});

it('shows an empty state before anything is recorded', function () {
    $this->get('/audit')->assertOk()->assertSee('No changes recorded yet');
});

it('filters the trail by flag, environment, and action', function () {
    $this->post('/flags', ['key' => 'one-flag', 'name' => 'One', 'type' => 'boolean']);
    $this->post('/flags', ['key' => 'two-flag', 'name' => 'Two', 'type' => 'boolean']);

    $one = Flag::where('key', 'one-flag')->firstOrFail();
    $this->put('/flags/two-flag/environments/staging', [
        'off_variant_id' => Flag::where('key', 'two-flag')->firstOrFail()->variants->first()->id,
        'fallthrough_variant_id' => Flag::where('key', 'two-flag')->firstOrFail()->variants->first()->id,
    ]);

    expect($this->get('/audit')->viewData('entries')->count())->toBe(3);

    expect($this->get('/audit?flag_id='.$one->id)->viewData('entries')->pluck('flag_id')->unique()->all())
        ->toBe([$one->id]);

    expect($this->get('/audit?action=flag.created')->viewData('entries')->pluck('action')->unique()->all())
        ->toBe(['flag.created']);

    $staging = Environment::where('name', 'staging')->firstOrFail();
    expect($this->get('/audit?environment_id='.$staging->id)->viewData('entries')->pluck('action')->all())
        ->toBe(['environment.state_changed']);
});

it('combines filters and reports a filtered empty state', function () {
    $this->post('/flags', ['key' => 'one-flag', 'name' => 'One', 'type' => 'boolean']);
    $one = Flag::where('key', 'one-flag')->firstOrFail();

    $this->get('/audit?flag_id='.$one->id.'&action=flag.archived')
        ->assertOk()
        ->assertSee('No audit entries match');
});

it('paginates the trail', function () {
    foreach (range(1, 30) as $index) {
        $this->post('/flags', ['key' => "flag-{$index}", 'name' => "Flag {$index}", 'type' => 'boolean']);
    }

    $response = $this->get('/audit');

    expect($response->viewData('entries')->count())->toBe(25)
        ->and($response->viewData('entries')->total())->toBe(30);

    expect($this->get('/audit?page=2')->viewData('entries')->count())->toBe(5);
});
