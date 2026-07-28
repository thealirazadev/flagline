<?php

namespace Database\Seeders;

use App\Models\Environment;
use App\Support\KeyGenerator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the two environments every install starts with. Operators are
     * created with app:create-user, never seeded.
     */
    public function run(): void
    {
        foreach (['production', 'staging'] as $name) {
            Environment::firstOrCreate(
                ['name' => $name],
                [
                    'sdk_key' => KeyGenerator::sdkKey(),
                    'signing_secret' => KeyGenerator::signingSecret(),
                ]
            );
        }
    }
}
