<?php

use App\Models\Environment;
use App\Support\RulesetSigner;

it('signs the body with an hmac over the environment signing secret', function () {
    $environment = new Environment(['signing_secret' => 'fl_sig_test']);
    $body = '{"version":1}';

    expect((new RulesetSigner)->sign($environment, $body))
        ->toBe('sha256='.hash_hmac('sha256', $body, 'fl_sig_test'));
});

it('rejects a tampered body or a signature made with another secret', function () {
    $signer = new RulesetSigner;
    $environment = new Environment(['signing_secret' => 'fl_sig_test']);
    $other = new Environment(['signing_secret' => 'fl_sig_other']);
    $signature = $signer->sign($environment, '{"version":1}');

    expect($signer->verify($environment, '{"version":1}', $signature))->toBeTrue()
        ->and($signer->verify($environment, '{"version":2}', $signature))->toBeFalse()
        ->and($signer->verify($other, '{"version":1}', $signature))->toBeFalse();
});
