<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Support\KeyGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    protected $model = Environment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->slug(1),
            'sdk_key' => KeyGenerator::sdkKey(),
            'signing_secret' => KeyGenerator::signingSecret(),
        ];
    }
}
