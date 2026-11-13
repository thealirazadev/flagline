<?php

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Support\KeyGenerator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Resolves `Authorization: Bearer <sdk_key>` to its environment, or answers 401. */
class AuthenticateSdkKey
{
    public const ATTRIBUTE = 'flagline.environment';

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->bearerToken();
        $environment = str_starts_with($key, KeyGenerator::SDK_KEY_PREFIX)
            ? Environment::where('sdk_key', $key)->first()
            : null;

        if ($environment === null || ! hash_equals($environment->sdk_key, $key)) {
            Log::warning('sdk.auth_failed', [
                'key_prefix' => $key === '' ? null : substr($key, 0, strlen(KeyGenerator::SDK_KEY_PREFIX) + 2).'...',
                'ip' => $request->ip(),
            ]);

            return self::error(401, 'unauthorized', 'The SDK key is missing or not recognized.');
        }

        $request->attributes->set(self::ATTRIBUTE, $environment);

        return $next($request);
    }

    /** The API error envelope (docs/api-contracts.md). */
    public static function error(int $status, string $code, string $message): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
