<?php

use App\Models\Environment;
use App\Models\Flag;
use App\Models\FlagEnvironment;
use App\Models\Rule;
use App\Models\Ruleset;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/*
 * A publish that loses its version race rolls back and runs the mutation again.
 * These tests make the next ruleset insert lose once and check that every
 * dashboard write still lands, in the database and in the published document.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::factory()->create());
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);

    $this->flag = Flag::where('key', 'checkout-redesign')->firstOrFail();
    $this->true = $this->flag->variants->firstWhere('value', 'true');
    $this->false = $this->flag->variants->firstWhere('value', 'false');
    $this->rulesUrl = '/flags/checkout-redesign/environments/production/rules';
});

/**
 * The next ruleset insert finds its version already taken by a rival row, so the
 * publish rolls back (taking the rival with it) and retries once.
 */
function loseNextPublishRace(): void
{
    $lost = false;

    Ruleset::creating(function (Ruleset $ruleset) use (&$lost) {
        if ($lost) {
            return;
        }

        $lost = true;

        DB::table('rulesets')->insert([
            'environment_id' => $ruleset->environment_id,
            'version' => $ruleset->version,
            'schema_version' => 1,
            'body' => '{}',
            'etag' => '"rival"',
            'signature' => 'sha256=rival',
            'created_at' => now(),
        ]);
    });
}

function productionState(): FlagEnvironment
{
    return FlagEnvironment::query()
        ->where('environment_id', Environment::where('name', 'production')->firstOrFail()->id)
        ->firstOrFail();
}

/**
 * @return array<string, mixed>
 */
function productionDocument(): array
{
    $environment = Environment::where('name', 'production')->firstOrFail();

    return json_decode(Ruleset::latestFor($environment)->firstOrFail()->body, true);
}

/**
 * @return array<string, mixed>
 */
function retryRule(string $description): array
{
    $flag = Flag::where('key', 'checkout-redesign')->firstOrFail();

    return [
        'description' => $description,
        'clauses' => [['attribute' => 'plan', 'operator' => 'eq', 'values' => $description]],
        'serve' => 'variant',
        'serve_variant_id' => $flag->variants->firstWhere('value', 'true')->id,
    ];
}

it('keeps a kill whose publish had to retry', function () {
    loseNextPublishRace();

    $this->post('/flags/checkout-redesign/environments/production/kill')->assertSessionHas('status');

    expect(productionState()->killed)->toBeTrue()
        ->and(productionDocument()['flags']['checkout-redesign']['killed'])->toBeTrue();
});

it('keeps an environment state change whose publish had to retry', function () {
    loseNextPublishRace();

    $this->put('/flags/checkout-redesign/environments/production', [
        'enabled' => '1',
        'off_variant_id' => $this->false->id,
        'serve' => 'variant',
        'serve_variant_id' => $this->true->id,
    ])->assertSessionHas('status');

    expect(productionState()->enabled)->toBeTrue()
        ->and(productionDocument()['flags']['checkout-redesign']['enabled'])->toBeTrue();
});

it('keeps a flag edit whose publish had to retry', function () {
    loseNextPublishRace();

    $this->put('/flags/checkout-redesign', ['name' => 'Checkout v2'])->assertSessionHas('status');

    expect($this->flag->fresh()->name)->toBe('Checkout v2');
});

it('keeps an archive whose publish had to retry', function () {
    loseNextPublishRace();

    $this->post('/flags/checkout-redesign/archive')->assertSessionHas('status');

    expect($this->flag->fresh()->isArchived())->toBeTrue()
        ->and(productionDocument()['flags'])->toBe([]);
});

it('keeps a rule edit whose publish had to retry', function () {
    $this->post($this->rulesUrl, retryRule('before'));
    $rule = Rule::sole();

    loseNextPublishRace();

    $this->put("/rules/{$rule->id}", retryRule('after'))->assertSessionHas('status', 'Rule saved.');

    expect($rule->fresh()->description)->toBe('after')
        ->and(productionDocument()['flags']['checkout-redesign']['rules'][0]['clauses'][0]['values'])->toBe(['after']);
});

it('keeps a rule move whose publish had to retry', function () {
    $this->post($this->rulesUrl, retryRule('first'));
    $this->post($this->rulesUrl, retryRule('second'));
    $second = Rule::where('description', 'second')->firstOrFail();

    loseNextPublishRace();

    $this->post("/rules/{$second->id}/move", ['direction' => 'up'])->assertSessionHas('status', 'Rule moved.');

    expect(Rule::orderBy('priority')->pluck('description', 'priority')->all())->toBe([0 => 'second', 1 => 'first']);
});

it('keeps a rule delete whose publish had to retry', function () {
    $this->post($this->rulesUrl, retryRule('first'));
    $this->post($this->rulesUrl, retryRule('second'));
    $first = Rule::where('description', 'first')->firstOrFail();

    loseNextPublishRace();

    $this->delete("/rules/{$first->id}")->assertSessionHas('status', 'Rule deleted.');

    expect(Rule::orderBy('priority')->pluck('description', 'priority')->all())->toBe([0 => 'second'])
        ->and(productionDocument()['flags']['checkout-redesign']['rules'])->toHaveCount(1);
});
