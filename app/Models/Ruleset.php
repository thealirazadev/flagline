<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published ruleset document. Rows are inserted by RulesetPublisher and never
 * updated: the body, etag, and signature of a version are reproducible forever.
 */
class Ruleset extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'environment_id',
        'version',
        'schema_version',
        'body',
        'etag',
        'signature',
        'published_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'schema_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The stored, quoted validator: version plus a short digest of the body, so
     * it changes whenever either does.
     */
    public static function etagFor(int $version, string $body): string
    {
        return '"'.$version.'-'.substr(hash('sha256', $body), 0, 16).'"';
    }

    /**
     * @param  Builder<Ruleset>  $query
     * @return Builder<Ruleset>
     */
    public function scopeLatestFor(Builder $query, Environment $environment): Builder
    {
        return $query->where('environment_id', $environment->id)->orderByDesc('version');
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
