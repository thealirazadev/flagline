<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\Flag;
use App\Models\FlagEnvironment;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlagEnvironment>
 */
class FlagEnvironmentFactory extends Factory
{
    protected $model = FlagEnvironment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flag_id' => Flag::factory(),
            'environment_id' => Environment::factory(),
            'enabled' => false,
            'killed' => false,
            'off_variant_id' => Variant::factory(),
            'fallthrough_variant_id' => null,
            'fallthrough_rollout' => null,
        ];
    }
}
