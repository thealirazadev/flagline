<?php

namespace App\Support;

use App\Models\Environment;

/**
 * HMAC-SHA256 over the exact stored body bytes. The signature is computed once
 * at publish time and delivered in the X-Flagline-Signature header, so serving
 * never re-serializes JSON and there is no canonicalization problem.
 */
class RulesetSigner
{
    public const HEADER = 'X-Flagline-Signature';

    private const PREFIX = 'sha256=';

    public function sign(Environment $environment, string $body): string
    {
        return self::PREFIX.hash_hmac('sha256', $body, (string) $environment->signing_secret);
    }

    /**
     * Constant-time comparison; a wrong secret and a tampered body are
     * indistinguishable to a caller timing this.
     */
    public function verify(Environment $environment, string $body, string $signature): bool
    {
        return hash_equals($this->sign($environment, $body), $signature);
    }
}
