<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Immutable trail. Rows are inserted and never updated or deleted.
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const ACTIONS = [
        'flag.created',
        'flag.updated',
        'flag.archived',
        'flag.killed',
        'flag.restored',
        'rule.created',
        'rule.updated',
        'rule.deleted',
        'environment.state_changed',
    ];

    protected $fillable = [
        'user_id',
        'flag_id',
        'environment_id',
        'action',
        'before',
        'after',
        'ruleset_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Write one trail row. Callers run this inside the same transaction as the
     * mutation it describes, so config never changes without an audit row.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?Flag $flag = null,
        ?Environment $environment = null,
        ?int $rulesetVersion = null,
    ): self {
        $entry = self::create([
            'user_id' => Auth::id(),
            'flag_id' => $flag?->id,
            'environment_id' => $environment?->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ruleset_version' => $rulesetVersion,
        ]);

        Log::info('audit.recorded', [
            'audit_log_id' => $entry->id,
            'action' => $action,
            'user_id' => $entry->user_id,
            'flag_id' => $entry->flag_id,
            'environment_id' => $entry->environment_id,
        ]);

        return $entry;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
}
