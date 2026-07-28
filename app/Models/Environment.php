<?php

namespace App\Models;

use App\Support\KeyGenerator;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'sdk_key',
        'signing_secret',
    ];

    protected $hidden = [
        'sdk_key',
        'signing_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signing_secret' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<FlagEnvironment, $this>
     */
    public function flagEnvironments(): HasMany
    {
        return $this->hasMany(FlagEnvironment::class);
    }

    /**
     * Leading identifier safe to put in logs; the full key never is.
     */
    public function sdkKeyPrefix(): string
    {
        return substr($this->sdk_key, 0, strlen(KeyGenerator::SDK_KEY_PREFIX) + 2).'...';
    }
}
