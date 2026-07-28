<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'flag_id' => null,
            'environment_id' => null,
            'action' => 'flag.created',
            'before' => null,
            'after' => ['name' => $this->faker->sentence(3)],
            'ruleset_version' => null,
        ];
    }
}
