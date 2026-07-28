<?php

use App\Support\KeyGenerator;

it('generates sdk keys with the documented prefix and length', function () {
    $key = KeyGenerator::sdkKey();

    expect($key)->toStartWith('fl_sdk_')
        ->and(strlen($key))->toBe(strlen('fl_sdk_') + 32)
        ->and($key)->toMatch('/^fl_sdk_[A-Za-z0-9]{32}$/');
});

it('generates signing secrets with the documented prefix and length', function () {
    $secret = KeyGenerator::signingSecret();

    expect($secret)->toStartWith('fl_sig_')
        ->and(strlen($secret))->toBe(strlen('fl_sig_') + 40)
        ->and($secret)->toMatch('/^fl_sig_[A-Za-z0-9]{40}$/');
});

it('does not repeat credentials', function () {
    $keys = array_map(fn () => KeyGenerator::sdkKey(), range(1, 200));
    $secrets = array_map(fn () => KeyGenerator::signingSecret(), range(1, 200));

    expect(array_unique($keys))->toHaveCount(200)
        ->and(array_unique($secrets))->toHaveCount(200);
});
