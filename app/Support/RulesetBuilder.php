<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\Flag;
use App\Models\FlagEnvironment;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use stdClass;

/**
 * Turns the config rows of one environment into the schema-v1 ruleset document
 * (docs/architecture.md). Variants are referenced by their index in the flag's
 * sort_order list, never by database id.
 */
class RulesetBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Environment $environment, int $version): array
    {
        $states = FlagEnvironment::query()
            ->where('environment_id', $environment->id)
            ->with(['flag.variants'])
            ->get()
            ->filter(fn (FlagEnvironment $state) => $state->flag !== null && ! $state->flag->isArchived())
            ->sortBy(fn (FlagEnvironment $state) => $state->flag->key);

        $flags = [];

        foreach ($states as $state) {
            $flags[$state->flag->key] = $this->flagDocument($state);
        }

        return $this->document($environment, $version, $flags, now()->toDateTimeImmutable());
    }

    /**
     * The document served for an environment that has never published. Its
     * published_at is the environment's creation time so the synthesized body,
     * and therefore its etag and signature, stay stable across requests.
     *
     * @return array<string, mixed>
     */
    public function buildEmpty(Environment $environment): array
    {
        $createdAt = ($environment->created_at ?? now())->toDateTimeImmutable();

        return $this->document($environment, 0, [], $createdAt);
    }

    /**
     * The exact bytes that get stored, signed, and served.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws JsonException when a flag key or variant value is not valid UTF-8
     */
    public function encode(array $document): string
    {
        if ($document['flags'] === []) {
            $document['flags'] = new stdClass;
        }

        return json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @param  array<string, mixed>  $flags
     * @return array<string, mixed>
     */
    private function document(
        Environment $environment,
        int $version,
        array $flags,
        DateTimeImmutable $publishedAt
    ): array {
        return [
            'schema_version' => (int) config('flagline.schema_version'),
            'environment' => $environment->name,
            'version' => $version,
            'published_at' => $publishedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'flags' => $flags,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flagDocument(FlagEnvironment $state): array
    {
        /** @var Flag $flag */
        $flag = $state->flag;

        $indexById = [];
        foreach ($flag->variants->values() as $index => $variant) {
            $indexById[$variant->id] = $index;
        }

        return [
            'type' => $flag->type,
            'enabled' => (bool) $state->enabled,
            'killed' => (bool) $state->killed,
            'variants' => $flag->variants->map(fn ($variant) => (string) $variant->value)->values()->all(),
            'off_variant' => $indexById[$state->off_variant_id] ?? 0,
            'rules' => [],
            'fallthrough' => $this->serve($state->fallthrough_variant_id, $state->fallthrough_rollout, $indexById),
        ];
    }

    /**
     * Exactly one of the two columns is non-null. A rollout wins when both are
     * set so a stale fixed variant can never shadow it.
     *
     * @param  array<int, array<string, mixed>>|null  $rollout
     * @param  array<int, int>  $indexById
     * @return array<string, mixed>
     */
    private function serve(?int $variantId, ?array $rollout, array $indexById): array
    {
        if (is_array($rollout) && $rollout !== []) {
            return [
                'rollout' => array_values(array_map(fn (array $entry) => [
                    'variant' => $indexById[(int) $entry['variant_id']] ?? 0,
                    'weight' => (int) $entry['weight'],
                ], $rollout)),
            ];
        }

        return ['variant' => $indexById[$variantId] ?? 0];
    }
}
