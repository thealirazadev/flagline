<?php

use App\Models\Environment;
use App\Models\Flag;
use App\Models\FlagEnvironment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::factory()->create());
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);

    $this->flag = Flag::where('key', 'checkout-redesign')->firstOrFail();
    $this->true = $this->flag->variants->firstWhere('value', 'true');
    $this->false = $this->flag->variants->firstWhere('value', 'false');
});

function stateFor(string $environment): FlagEnvironment
{
    return FlagEnvironment::query()
        ->where('environment_id', Environment::where('name', $environment)->firstOrFail()->id)
        ->firstOrFail();
}

it('defaults a new flag to off with false off and true fallthrough', function () {
    $state = stateFor('production');

    expect($state->enabled)->toBeFalse()
        ->and($state->killed)->toBeFalse()
        ->and($state->off_variant_id)->toBe($this->false->id)
        ->and($state->fallthrough_variant_id)->toBe($this->true->id);
});

it('enables a flag in one environment without touching the other', function () {
    $this->put('/flags/checkout-redesign/environments/staging', [
        'enabled' => '1',
        'off_variant_id' => $this->false->id,
        'fallthrough_variant_id' => $this->true->id,
    ])->assertRedirect('/flags/checkout-redesign/edit?env=staging');

    expect(stateFor('staging')->enabled)->toBeTrue()
        ->and(stateFor('production')->enabled)->toBeFalse();
});

it('changes the off variant in one environment only', function () {
    $this->put('/flags/checkout-redesign/environments/staging', [
        'off_variant_id' => $this->true->id,
        'fallthrough_variant_id' => $this->false->id,
    ])->assertSessionHasNoErrors();

    expect(stateFor('staging')->off_variant_id)->toBe($this->true->id)
        ->and(stateFor('production')->off_variant_id)->toBe($this->false->id);
});

it('treats a missing enabled checkbox as disabled', function () {
    $this->put('/flags/checkout-redesign/environments/production', [
        'enabled' => '1',
        'off_variant_id' => $this->false->id,
        'fallthrough_variant_id' => $this->true->id,
    ]);
    expect(stateFor('production')->enabled)->toBeTrue();

    $this->put('/flags/checkout-redesign/environments/production', [
        'off_variant_id' => $this->false->id,
        'fallthrough_variant_id' => $this->true->id,
    ]);
    expect(stateFor('production')->enabled)->toBeFalse();
});

it('rejects a variant that belongs to a different flag', function () {
    $this->post('/flags', ['key' => 'other-flag', 'name' => 'Other', 'type' => 'boolean']);
    $foreign = Flag::where('key', 'other-flag')->firstOrFail()->variants->first();

    $this->put('/flags/checkout-redesign/environments/production', [
        'off_variant_id' => $foreign->id,
        'fallthrough_variant_id' => $foreign->id,
    ])->assertSessionHasErrors(['off_variant_id', 'fallthrough_variant_id']);

    expect(stateFor('production')->off_variant_id)->toBe($this->false->id);
});

it('requires both variant selections', function () {
    $this->put('/flags/checkout-redesign/environments/production', [])
        ->assertSessionHasErrors(['off_variant_id', 'fallthrough_variant_id']);
});

it('refuses state changes on an archived flag', function () {
    $this->followingRedirects()->post('/flags/checkout-redesign/archive');

    $this->put('/flags/checkout-redesign/environments/production', [
        'enabled' => '1',
        'off_variant_id' => $this->false->id,
        'fallthrough_variant_id' => $this->true->id,
    ])->assertSessionHas('error');

    expect(stateFor('production')->enabled)->toBeFalse();
});
