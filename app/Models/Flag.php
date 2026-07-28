<?php

namespace App\Models;

use Database\Factories\FlagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flag extends Model
{
    /** @use HasFactory<FlagFactory> */
    use HasFactory;

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_STRING = 'string';

    /**
     * Flag keys are referenced by SDKs and by the ruleset document, so the key
     * and the type are set at creation and never mass-updated afterwards.
     */
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,99}$/';

    protected $fillable = [
        'key',
        'name',
        'description',
        'type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Variant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class)->orderBy('sort_order');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isBoolean(): bool
    {
        return $this->type === self::TYPE_BOOLEAN;
    }

    /**
     * @param  Builder<Flag>  $query
     * @return Builder<Flag>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
