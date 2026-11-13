<?php

use App\Models\Environment;
use App\Models\Ruleset;
use App\Support\RulesetPublisher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->production = Environment::where('name', 'production')->firstOrFail();
});

it('retries once when another publisher takes the version first', function () {
    Log::spy();
    $calls = 0;

    app(RulesetPublisher::class)->publish($this->production, function (array $versions) use (&$calls) {
        $calls++;
        if ($calls === 1) {
            // A rival publisher commits this exact version mid-transaction.
            Ruleset::create([
                'environment_id' => $this->production->id,
                'version' => $versions[$this->production->id],
                'schema_version' => 1,
                'body' => '{}',
                'etag' => '"rival"',
                'signature' => 'sha256=rival',
            ]);
        }
    });

    expect($calls)->toBe(2)
        ->and(Ruleset::where('environment_id', $this->production->id)->pluck('version')->all())->toBe([1]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $event) => $event === 'ruleset.publish_conflict')->once();
});

it('does not retry a conflict raised by the mutation itself', function () {
    expect(fn () => app(RulesetPublisher::class)->publish($this->production, function () {
        foreach ([1, 1] as $version) {
            Ruleset::create([
                'environment_id' => $this->production->id, 'version' => 900 + $version, 'schema_version' => 1,
                'body' => '{}', 'etag' => '"x"', 'signature' => 'sha256=x',
            ]);
        }
    }))->toThrow(UniqueConstraintViolationException::class);

    expect(Ruleset::count())->toBe(0);
});
