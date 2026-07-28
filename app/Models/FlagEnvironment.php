<?php

namespace App\Models;

use Database\Factories\FlagEnvironmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlagEnvironment extends Model
{
    /** @use HasFactory<FlagEnvironmentFactory> */
    use HasFactory;

    protected $fillable = [
        'flag_id',
        'environment_id',
        'enabled',
        'killed',
        'off_variant_id',
        'fallthrough_variant_id',
        'fallthrough_rollout',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'killed' => 'boolean',
            'fallthrough_rollout' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Flag, $this>
     */
    public function flag(): BelongsTo
    {
        return $this->belongsTo(Flag::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function offVariant(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'off_variant_id');
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function fallthroughVariant(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'fallthrough_variant_id');
    }

    /**
     * The audit trail stores this shape as the before/after snapshot.
     *
     * @return array<string, mixed>
     */
    public function stateSnapshot(): array
    {
        return [
            'enabled' => $this->enabled,
            'killed' => $this->killed,
            'off_variant_id' => $this->off_variant_id,
            'fallthrough_variant_id' => $this->fallthrough_variant_id,
            'fallthrough_rollout' => $this->fallthrough_rollout,
        ];
    }
}
