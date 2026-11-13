<?php

use App\Models\Environment;
use App\Models\Ruleset;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::factory()->create());
    // Each flag create publishes to every environment: versions 1..5 in each.
    foreach (range(1, 5) as $i) {
        $this->post('/flags', ['key' => "flag-{$i}", 'name' => "Flag {$i}", 'type' => 'boolean']);
    }
});

it('keeps the newest versions per environment and still serves the latest', function () {
    config(['flagline.ruleset_keep' => 2]);
    $production = Environment::where('name', 'production')->firstOrFail();

    $this->artisan('app:prune-rulesets')->assertSuccessful();

    expect(Ruleset::where('environment_id', $production->id)->orderBy('version')->pluck('version')->all())->toBe([4, 5])
        ->and(Ruleset::count())->toBe(2 * Environment::count());

    $this->get('/api/v1/ruleset', ['Authorization' => 'Bearer '.$production->sdk_key])
        ->assertOk()->assertJsonPath('version', 5);
});

it('leaves environments under the limit alone', function () {
    config(['flagline.ruleset_keep' => 50]);
    $before = Ruleset::count();

    $this->artisan('app:prune-rulesets')->assertSuccessful();

    expect(Ruleset::count())->toBe($before);
});
