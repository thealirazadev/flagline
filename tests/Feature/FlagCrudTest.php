<?php

use App\Models\Flag;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::factory()->create());
});

it('shows an empty state before any flag exists', function () {
    $this->get('/flags')->assertOk()->assertSee('No flags yet');
});

it('creates a boolean flag with exactly the true and false variants', function () {
    $this->post('/flags', [
        'key' => 'checkout-redesign',
        'name' => 'Checkout redesign',
        'description' => 'Dark launch of the new checkout.',
        'type' => 'boolean',
    ])->assertRedirect('/flags/checkout-redesign/edit');

    $flag = Flag::where('key', 'checkout-redesign')->firstOrFail();

    expect($flag->variants->pluck('value')->all())->toBe(['true', 'false'])
        ->and($flag->variants->pluck('sort_order')->all())->toBe([0, 1]);
});

it('creates one environment state row per environment, all off', function () {
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);

    $states = Flag::where('key', 'checkout-redesign')->firstOrFail()->flagEnvironments;

    expect($states)->toHaveCount(2)
        ->and($states->pluck('enabled')->all())->toBe([false, false])
        ->and($states->pluck('killed')->all())->toBe([false, false]);
});

it('creates a string flag keeping the submitted variant order', function () {
    $this->post('/flags', [
        'key' => 'pricing-page-copy',
        'name' => 'Pricing copy',
        'type' => 'string',
        'variants' => ['control', 'value-first', 'social-proof'],
    ])->assertRedirect('/flags/pricing-page-copy/edit');

    expect(Flag::where('key', 'pricing-page-copy')->firstOrFail()->variants->pluck('value')->all())
        ->toBe(['control', 'value-first', 'social-proof']);
});

it('rejects invalid flag keys', function (string $key) {
    $this->post('/flags', ['key' => $key, 'name' => 'Name', 'type' => 'boolean'])
        ->assertSessionHasErrors('key');

    expect(Flag::count())->toBe(0);
})->with(['Uppercase', 'has:colon', '-leading-hyphen', '_leading-underscore', 'has space', 'has.dot', '']);

it('accepts a key at the 100 character limit and rejects a longer one', function () {
    $atLimit = 'a'.str_repeat('b', 99);
    $this->post('/flags', ['key' => $atLimit, 'name' => 'Name', 'type' => 'boolean'])
        ->assertSessionHasNoErrors();

    $this->post('/flags', ['key' => 'a'.str_repeat('b', 100), 'name' => 'Name', 'type' => 'boolean'])
        ->assertSessionHasErrors('key');

    expect(Flag::count())->toBe(1);
});

it('rejects a duplicate flag key so a double submit creates one flag', function () {
    $payload = ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean'];

    $this->post('/flags', $payload)->assertSessionHasNoErrors();
    $this->post('/flags', $payload)->assertSessionHasErrors('key');

    expect(Flag::where('key', 'checkout-redesign')->count())->toBe(1);
});

it('requires string flags to have at least two distinct variants', function (array $variants) {
    $this->post('/flags', [
        'key' => 'pricing-page-copy', 'name' => 'Pricing', 'type' => 'string', 'variants' => $variants,
    ])->assertSessionHasErrors('variants');

    expect(Flag::count())->toBe(0);
})->with([
    'one value' => [['control']],
    'duplicates' => [['control', 'control']],
    'blank rows only' => [[' ', '']],
]);

it('gives field errors on an empty create form without erroring', function () {
    $this->post('/flags', [])->assertSessionHasErrors(['key', 'name', 'type']);

    expect(Flag::count())->toBe(0);
});

it('accepts a 255 character name and rejects a longer one', function () {
    $this->post('/flags', ['key' => 'long-name', 'name' => str_repeat('n', 255), 'type' => 'boolean'])
        ->assertSessionHasNoErrors();

    $this->post('/flags', ['key' => 'longer-name', 'name' => str_repeat('n', 256), 'type' => 'boolean'])
        ->assertSessionHasErrors('name');

    expect(Flag::count())->toBe(1);
});

it('adds and removes variant rows through the round trip create form', function () {
    $this->get('/flags/create?type=string&variants[]=a&variants[]=b&action=add')
        ->assertOk()->assertSee('Variant 3');

    $this->get('/flags/create?type=string&variants[]=a&variants[]=b&variants[]=c&remove=2')
        ->assertOk()->assertDontSee('Variant 3');
});

it('updates the name and description but never the key or type', function () {
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Old', 'type' => 'boolean']);

    $this->put('/flags/checkout-redesign', [
        'name' => 'New name',
        'description' => 'New description',
        'key' => 'forged-key',
        'type' => 'string',
    ])->assertSessionHasNoErrors();

    $flag = Flag::where('key', 'checkout-redesign')->firstOrFail();

    expect($flag->name)->toBe('New name')
        ->and($flag->description)->toBe('New description')
        ->and($flag->type)->toBe('boolean');
    expect(Flag::where('key', 'forged-key')->exists())->toBeFalse();
});

it('archives a flag, hides it from the index, and makes it read only', function () {
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);

    $this->followingRedirects()->post('/flags/checkout-redesign/archive')->assertOk();

    expect(Flag::where('key', 'checkout-redesign')->firstOrFail()->isArchived())->toBeTrue();

    $index = $this->get('/flags');
    expect($index->viewData('flags')->pluck('key')->all())->toBe([]);

    $archivedIndex = $this->get('/flags?archived=1');
    expect($archivedIndex->viewData('flags')->pluck('key')->all())->toBe(['checkout-redesign']);

    $this->put('/flags/checkout-redesign', ['name' => 'Nope'])->assertSessionHas('error');
    expect(Flag::where('key', 'checkout-redesign')->firstOrFail()->name)->toBe('Checkout');

    $this->post('/flags/checkout-redesign/archive')->assertSessionHas('error');
});

it('filters the index by search term and type', function () {
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);
    $this->post('/flags', [
        'key' => 'pricing-page-copy', 'name' => 'Pricing', 'type' => 'string', 'variants' => ['a', 'b'],
    ]);

    expect($this->get('/flags?q=checkout')->viewData('flags')->pluck('key')->all())
        ->toBe(['checkout-redesign']);
    expect($this->get('/flags?type=string')->viewData('flags')->pluck('key')->all())
        ->toBe(['pricing-page-copy']);
    expect($this->get('/flags?q=nothing-matches')->viewData('flags')->pluck('key')->all())
        ->toBe([]);
});

it('renders the edit screen for each environment', function () {
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);

    $this->get('/flags/checkout-redesign/edit?env=production')->assertOk()->assertSee('State in production');
    $this->get('/flags/checkout-redesign/edit?env=staging')->assertOk()->assertSee('State in staging');
    $this->get('/flags/checkout-redesign/edit?env=nonexistent')->assertOk()->assertSee('State in production');
});
