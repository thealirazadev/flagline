<?php

namespace App\Http\Requests\Concerns;

use App\Models\Flag;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\Validator;

/**
 * A serve is either one fixed variant or a rollout: one weight per variant, in basis
 * points, summing to exactly 10000 and splitting traffic over at least two variants.
 * Used by the rule editor and the fallthrough editor.
 */
trait ValidatesServe
{
    public const TOTAL_WEIGHT = 10000;

    abstract protected function serveFlag(): Flag;

    /**
     * @return array<string, mixed>
     */
    protected function serveRules(): array
    {
        $variantIds = $this->serveFlag()->variants->pluck('id')->all();

        return [
            'serve' => ['required', ValidationRule::in(['variant', 'rollout'])],
            'serve_variant_id' => ['required_if:serve,variant', 'nullable', 'integer', ValidationRule::in($variantIds)],
            'weights' => ['required_if:serve,rollout', 'nullable', 'array'],
            'weights.*' => ['nullable', 'integer', 'min:0', 'max:'.self::TOTAL_WEIGHT],
        ];
    }

    protected function validateRollout(Validator $validator): void
    {
        if ($this->input('serve') !== 'rollout' || $validator->errors()->has('weights.*')) {
            return;
        }

        $weights = $this->rolloutWeights();
        if (array_sum($weights) !== self::TOTAL_WEIGHT) {
            $validator->errors()->add('weights', 'Weights must add up to exactly 10000 basis points (100%).');
        } elseif (count(array_filter($weights)) < 2) {
            $validator->errors()->add('weights', 'A rollout needs at least two variants with a weight; serve a single variant instead.');
        }
    }

    /**
     * The serve as stored: [serve_variant_id, rollout]. Rollout entries follow the flag's
     * variant order, which is what keeps raising one weight from reshuffling users.
     *
     * @return array{0: int|null, 1: array<int, array{variant_id: int, weight: int}>|null}
     */
    public function serveColumns(): array
    {
        if ($this->input('serve') !== 'rollout') {
            return [(int) $this->input('serve_variant_id'), null];
        }

        $rollout = [];
        foreach ($this->rolloutWeights() as $variantId => $weight) {
            $rollout[] = ['variant_id' => $variantId, 'weight' => $weight];
        }

        return [null, $rollout];
    }

    /**
     * @return array<int, int> weight per variant id, in variant order
     */
    private function rolloutWeights(): array
    {
        $input = (array) $this->input('weights', []);
        $weights = [];
        foreach ($this->serveFlag()->variants as $variant) {
            $weights[$variant->id] = (int) ($input[$variant->id] ?? 0);
        }

        return $weights;
    }

    /**
     * True when the new rollout gives any variant less weight than before: those users'
     * buckets move to another variant (docs/architecture.md, monotonic rollout invariant).
     * A fixed serve counts as its variant holding all 10000 basis points.
     *
     * @param  array<int, array<string, mixed>>|null  $beforeRollout
     * @param  array<int, array<string, mixed>>|null  $afterRollout
     */
    public static function shrinksRollout(?int $beforeVariantId, ?array $beforeRollout, ?array $afterRollout): bool
    {
        if (! $afterRollout) {
            return false;
        }

        $old = $beforeRollout
            ? array_column($beforeRollout, 'weight', 'variant_id')
            : [$beforeVariantId => self::TOTAL_WEIGHT];

        foreach ($afterRollout as $entry) {
            if ((int) $entry['weight'] < (int) ($old[$entry['variant_id']] ?? 0)) {
                return true;
            }
        }

        return false;
    }
}
