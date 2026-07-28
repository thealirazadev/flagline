<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Credential generation for environments. SDK keys are stored in plaintext so
 * the bearer lookup stays an indexed query; signing secrets are encrypted at
 * rest by the model cast.
 */
class KeyGenerator
{
    public const SDK_KEY_PREFIX = 'fl_sdk_';

    public const SIGNING_SECRET_PREFIX = 'fl_sig_';

    public static function sdkKey(): string
    {
        return self::SDK_KEY_PREFIX.Str::random(32);
    }

    public static function signingSecret(): string
    {
        return self::SIGNING_SECRET_PREFIX.Str::random(40);
    }
}
