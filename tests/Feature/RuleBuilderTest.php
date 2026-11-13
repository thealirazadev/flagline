<?php

use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\Flag;
use App\Models\Rule;
use App\Models\Ruleset;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::factory()->create());
    $this->post('/flags', ['key' => 'checkout-redesign', 'name' => 'Checkout', 'type' => 'boolean']);

    $this->flag = Flag::where('key', 'checkout-redesign')->firstOrFail();
    $this->true = $this->flag->variants->firstWhere('value', 'true');
    $this->false = $this->flag->variants->firstWhere('value', 'false');
    $this->url = '/flags/checkout-redesign/environments/production/rules';
});

function rule(array $overrides = []): array
{
    $flag = Flag::where('key', 'checkout-redesign')->firstOrFail();

    return $overrides + [
        'form_id' => 'new',
        'description' => 'Pro plans',
        'clauses' => [['attribute' => 'plan', 'operator' => 'in', 'values' => "pro\nenterprise"]],
        'serve' => 'variant',
        'serve_variant_id' => $flag->variants->firstWhere('value', 'true')->id,
    ];
}

function document(): array
{
    $environment = Environment::where('name', 'production')->firstOrFail();
    $body = Ruleset::where('environment_id', $environment->id)->orderByDesc('version')->firstOrFail()->body;

    return json_decode($body, true)['flags']['checkout-redesign'];
}

function version(): int
{
    $environment = Environment::where('name', 'production')->firstOrFail();

    return (int) Ruleset::where('environment_id', $environment->id)->max('version');
}

function priorities(): array
{
    return Rule::orderBy('priority')->pluck('description', 'priority')->all();
}

it('adds a rule and publishes it with clauses and serve', function () {
    $before = version();
    $this->post($this->url, rule())->assertRedirect()->assertSessionHas('status', 'Rule added.');

    expect(version())->toBe($before + 1)
        ->and(document()['rules'])->toBe([[
            'clauses' => [['attribute' => 'plan', 'op' => 'in', 'values' => ['pro', 'enterprise']]],
            'serve' => ['variant' => 0],
        ]])
        ->and(AuditLog::where('action', 'rule.created')->count())->toBe(1);
});

it('reorders rules, swaps them in the document, and bumps the version once per step', function () {
    $this->post($this->url, rule(['description' => 'first']));
    $this->post($this->url, rule(['description' => 'second', 'clauses' => [['attribute' => 'country', 'operator' => 'eq', 'values' => 'PK']]]));
    $second = Rule::where('description', 'second')->firstOrFail();

    $before = version();
    $this->post("/rules/{$second->id}/move", ['direction' => 'up'])->assertSessionHas('status', 'Rule moved.');

    expect(priorities())->toBe([0 => 'second', 1 => 'first'])
        ->and(version())->toBe($before + 1)
        ->and(document()['rules'][0]['clauses'][0]['attribute'])->toBe('country');
});

it('resequences priorities with no gap after a delete', function () {
    foreach (['a', 'b', 'c'] as $name) {
        $this->post($this->url, rule(['description' => $name]));
    }
    $before = version();

    $this->delete('/rules/'.Rule::where('description', 'a')->value('id'))->assertSessionHas('status', 'Rule deleted.');

    expect(priorities())->toBe([0 => 'b', 1 => 'c'])
        ->and(version())->toBe($before + 1)
        ->and(AuditLog::where('action', 'rule.deleted')->count())->toBe(1);
});

it('fails gracefully when a rule is deleted twice from a stale tab', function () {
    $this->post($this->url, rule());
    $id = Rule::value('id');

    $this->delete("/rules/{$id}");
    $this->from('/flags/checkout-redesign/edit')->delete("/rules/{$id}")
        ->assertRedirect('/flags/checkout-redesign/edit')
        ->assertSessionHas('error');
});

it('accepts weights that sum to 10000 and rejects the rest', function (array $weights, bool $ok) {
    $response = $this->post($this->url, rule([
        'serve' => 'rollout',
        'weights' => [$this->true->id => $weights[0], $this->false->id => $weights[1]],
    ]));

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors();
})->with([
    '3000/7000' => [[3000, 7000], true],
    '3000/6000' => [[3000, 6000], false],
    'negative' => [[-1000, 11000], false],
    'over 10000' => [[10001, -1], false],
    'single variant' => [[10000, 0], false],
]);

it('publishes a rollout serve by variant index in variant order', function () {
    $this->post($this->url, rule([
        'serve' => 'rollout',
        'weights' => [$this->true->id => 3000, $this->false->id => 7000],
    ]));

    expect(document()['rules'][0]['serve'])->toBe(['rollout' => [
        ['variant' => 0, 'weight' => 3000],
        ['variant' => 1, 'weight' => 7000],
    ]]);
});

it('validates semver clause values at save time', function (string $value, bool $ok) {
    $response = $this->post($this->url, rule([
        'clauses' => [['attribute' => 'app_version', 'operator' => 'semver_gte', 'values' => $value]],
    ]));

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('clauses.0.values');
})->with([
    '1.2.x rejected' => ['1.2.x', false],
    'prerelease accepted' => ['1.2.3-beta.1', true],
]);

it('rejects forged operators, oversized values, and multi-value eq', function (array $clause, string $field) {
    $this->post($this->url, rule(['clauses' => [$clause]]))->assertSessionHasErrors($field);
    expect(Rule::count())->toBe(0);
})->with([
    'forged operator' => [['attribute' => 'plan', 'operator' => 'regex', 'values' => 'x'], 'clauses.0.operator'],
    '1000 char value' => [['attribute' => 'plan', 'operator' => 'eq', 'values' => str_repeat('x', 1000)], 'clauses.0.values.0'],
    'eq with two values' => [['attribute' => 'plan', 'operator' => 'eq', 'values' => "a\nb"], 'clauses.0.values'],
    'no values' => [['attribute' => 'plan', 'operator' => 'in', 'values' => "\n "], 'clauses.0.values'],
]);

it('adds and removes clause rows without saving', function () {
    $this->from('/flags/checkout-redesign/edit')
        ->post($this->url, rule(['clause_action' => 'add']))
        ->assertRedirect('/flags/checkout-redesign/edit')
        ->assertSessionHasInput('clauses', [
            ['attribute' => 'plan', 'operator' => 'in', 'values' => "pro\nenterprise"],
            ['attribute' => '', 'operator' => 'eq', 'values' => ''],
        ]);

    $two = rule(['clauses' => [
        ['attribute' => 'plan', 'operator' => 'in', 'values' => 'pro'],
        ['attribute' => 'country', 'operator' => 'eq', 'values' => 'PK'],
    ]]);
    $this->post($this->url, $two + ['clause_action' => 'remove:0'])
        ->assertSessionHasInput('clauses', [['attribute' => 'country', 'operator' => 'eq', 'values' => 'PK']]);

    expect(Rule::count())->toBe(0);
});

it('warns when a save lowers a rollout weight but not when it raises one', function () {
    $this->post($this->url, rule(['serve' => 'rollout', 'weights' => [$this->true->id => 2000, $this->false->id => 8000]]));
    $id = Rule::value('id');
    $update = fn (int $true) => rule([
        'form_id' => "rule-{$id}",
        'serve' => 'rollout',
        'weights' => [$this->true->id => $true, $this->false->id => 10000 - $true],
    ]);

    // Raising true from 20% to 30% lowers false, which is a shrink.
    $this->put("/rules/{$id}", $update(3000))->assertSessionHas('warning');
    // Lowering true back: also a shrink.
    $this->put("/rules/{$id}", $update(1000))->assertSessionHas('warning');
    // Same weights: no shrink.
    $this->put("/rules/{$id}", $update(1000))->assertSessionMissing('warning');
});

it('warns on a fallthrough that moves from one variant to a rollout', function () {
    $this->put('/flags/checkout-redesign/environments/production', [
        'off_variant_id' => $this->false->id,
        'serve' => 'rollout',
        'weights' => [$this->true->id => 5000, $this->false->id => 5000],
    ])->assertSessionHasNoErrors()->assertSessionHas('warning');

    expect(document()['fallthrough'])->toBe(['rollout' => [
        ['variant' => 0, 'weight' => 5000],
        ['variant' => 1, 'weight' => 5000],
    ]]);
});

it('renders the rule builder on the flag edit screen', function () {
    $this->post($this->url, rule());

    $this->get('/flags/checkout-redesign/edit')->assertOk()
        ->assertSee(['Targeting rules in production', 'Pro plans', 'Add clause', 'Save rule', 'Percentage rollout']);
});
