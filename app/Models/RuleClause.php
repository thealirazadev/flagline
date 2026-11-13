<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleClause extends Model
{
    public const OPERATORS = [
        'eq', 'in', 'contains', 'semver_eq', 'semver_gt', 'semver_gte', 'semver_lt', 'semver_lte',
    ];

    /** Operators that compare against values[0] only. */
    public const SINGLE_VALUE_OPERATORS = [
        'eq', 'contains', 'semver_eq', 'semver_gt', 'semver_gte', 'semver_lt', 'semver_lte',
    ];

    /** major.minor.patch, optional -prerelease, optional +build (ignored by the SDKs). */
    public const SEMVER_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
        .'(-(0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(\.(0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?'
        .'(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$/';

    protected $fillable = ['rule_id', 'attribute', 'operator', 'values'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['values' => 'array'];
    }

    /**
     * @return BelongsTo<Rule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    public static function isSemver(string $value): bool
    {
        return preg_match(self::SEMVER_PATTERN, $value) === 1;
    }
}
