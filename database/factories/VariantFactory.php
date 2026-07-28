<?php

namespace Database\Factories;

use App\Models\Flag;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    protected $model = Variant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flag_id' => Flag::factory(),
            'value' => $this->faker->unique()->word(),
            'sort_order' => 0,
        ];
    }
}
