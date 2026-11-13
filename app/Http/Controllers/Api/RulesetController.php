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

        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return response('', 304, $headers);
        }

        return response($body, 200, $headers + [
            'Content-Type' => 'application/json',
            'X-Flagline-Signature' => $signature,
        ]);
    }
}
