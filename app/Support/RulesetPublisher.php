<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\Ruleset;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place the publish transaction is opened. Config mutation, audit row,
 * and the new ruleset version commit together or not at all, so the API only
 * ever sees fully published versions (docs/architecture.md).
 */
class RulesetPublisher
{
    /**
     * One retry, per the version-monotonicity invariant: the loser of a version
     * race re-reads config in a fresh transaction and publishes merged truth.
     */
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly RulesetBuilder $builder,
        private readonly RulesetSigner $signer,
    ) {}

    /**
     * Publish a change scoped to one environment.
     *
     * @param  Closure(array<int, int>): mixed  $mutation  receives the versions it is publishing, keyed by environment id
     */
    public function publish(Environment $environment, Closure $mutation): mixed
    {
        return $this->run([$environment], $mutation);
    }

    /**
     * Publish a flag-level change (create, update, archive), which alters the
     * document of every environment.
     *
     * @param  Closure(array<int, int>): mixed  $mutation
     */
    public function publishToAll(Closure $mutation): mixed
    {
        return $this->run(Environment::orderBy('id')->get()->all(), $mutation);
    }

    /**
     * @param  list<Environment>  $environments
     * @param  Closure(array<int, int>): mixed  $mutation
     */
    private function run(array $environments, Closure $mutation): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $lostTheVersion = false;

            try {
                return DB::transaction(function () use ($environments, $mutation, &$lostTheVersion) {
                    $versions = [];

                    foreach ($environments as $environment) {
                        $versions[$environment->id] = $this->nextVersion($environment);
                    }

                    $result = $mutation($versions);

                    try {
                        foreach ($environments as $environment) {
                            $this->insert($environment, $versions[$environment->id]);
                        }
                    } catch (UniqueConstraintViolationException $e) {
                        // Another publisher committed this version first; only
                        // this case is retryable, not a conflict the mutation
                        // itself raised.
                        $lostTheVersion = true;

                        throw $e;
                    }

                    return $result;
                });
            } catch (UniqueConstraintViolationException $e) {
                if (! $lostTheVersion || $attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }

                Log::warning('ruleset.publish_conflict', [
                    'attempt' => $attempt,
                    'environments' => array_map(fn (Environment $environment) => $environment->name, $environments),
                ]);
            }
        }
    }

    private function nextVersion(Environment $environment): int
    {
        return 1 + (int) Ruleset::where('environment_id', $environment->id)->max('version');
    }

    private function insert(Environment $environment, int $version): Ruleset
    {
        $document = $this->builder->build($environment, $version);
        $body = $this->builder->encode($document);

        $ruleset = Ruleset::create([
            'environment_id' => $environment->id,
            'version' => $version,
            'schema_version' => $document['schema_version'],
            'body' => $body,
            'etag' => Ruleset::etagFor($version, $body),
            'signature' => $this->signer->sign($environment, $body),
            'published_by' => Auth::id(),
        ]);

        Log::info('ruleset.published', [
            'environment' => $environment->name,
            'version' => $version,
            'flags' => count($document['flags']),
            'bytes' => strlen($body),
        ]);

        return $ruleset;
    }
}
