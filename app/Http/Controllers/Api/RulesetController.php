<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSdkKey;
use App\Models\Environment;
use App\Models\Ruleset;
use App\Support\RulesetBuilder;
use App\Support\RulesetSigner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RulesetController extends Controller
{
    public function __construct(
        private readonly RulesetBuilder $builder,
        private readonly RulesetSigner $signer,
    ) {}

    /**
     * Serves the stored bytes of the latest published version; nothing is re-serialized,
     * so the signature computed at publish time covers exactly what is sent.
     */
    public function __invoke(Request $request): Response
    {
        /** @var Environment $environment */
        $environment = $request->attributes->get(AuthenticateSdkKey::ATTRIBUTE);
        $ruleset = Ruleset::latestFor($environment)->first();

        if ($ruleset !== null) {
            [$body, $etag, $signature] = [$ruleset->body, $ruleset->etag, $ruleset->signature];
        } else {
            $body = $this->builder->encode($this->builder->buildEmpty($environment));
            [$etag, $signature] = [Ruleset::etagFor(0, $body), $this->signer->sign($environment, $body)];
        }

        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache'];

        if (self::matchesIfNoneMatch($etag, (string) $request->header('If-None-Match'))) {
            return response('', 304, $headers);
        }

        return response($body, 200, $headers + [
            'Content-Type' => 'application/json',
            'X-Flagline-Signature' => $signature,
        ]);
    }

    /**
     * If-None-Match uses the weak comparison (RFC 9110, section 13.1.2): a proxy that
     * compresses the response may hand the client W/"..." for our strong tag, and that
     * client must still get its 304. `*` matches because a document always exists.
     */
    private static function matchesIfNoneMatch(string $etag, string $header): bool
    {
        foreach (array_map('trim', explode(',', $header)) as $candidate) {
            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/'.$etag) {
                return true;
            }
        }

        return false;
    }
}
