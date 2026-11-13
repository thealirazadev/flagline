<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A targeting rule: all clauses must match, then the serve applies. Exactly one of
 * serve_variant_id / serve_rollout is non-null.
 */
class Rule extends Model
{
    protected $fillable = [
        'flag_environment_id',
        'priority',
        'description',
        'serve_variant_id',
        'serve_rollout',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'serve_rollout' => 'array',
        ];
    }

    /**
     * @return BelongsTo<FlagEnvironment, $this>
     */
    public function flagEnvironment(): BelongsTo
    {
        return $this->belongsTo(FlagEnvironment::class);
    }

    /**
     * @return HasMany<RuleClause, $this>
     */
    public function clauses(): HasMany
    {
        return $this->hasMany(RuleClause::class)->orderBy('id');
    }

    /**
     * The audit trail stores this shape as the before/after snapshot.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'priority' => $this->priority,
            'description' => $this->description,
            'serve_variant_id' => $this->serve_variant_id,
            'serve_rollout' => $this->serve_rollout,
            'clauses' => $this->clauses->map(fn (RuleClause $clause) => [
                'attribute' => $clause->attribute,
                'operator' => $clause->operator,
                'values' => $clause->values,
            ])->all(),
        ];
    }
}
