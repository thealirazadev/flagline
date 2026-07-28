<?php

namespace Database\Factories;

use App\Models\Flag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Flag>
 */
class FlagFactory extends Factory
{
    protected $model = Flag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(3),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->sentence(),
            'type' => Flag::TYPE_BOOLEAN,
        ];
    }

    public function archived(): self
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }

    public function stringType(): self
    {
        return $this->state(fn () => ['type' => Flag::TYPE_STRING]);
    }
}
