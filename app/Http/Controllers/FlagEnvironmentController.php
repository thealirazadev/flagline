<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFlagEnvironmentRequest;
use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\Flag;
use App\Models\FlagEnvironment;
use App\Support\RulesetPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class FlagEnvironmentController extends Controller
{
    public function __construct(private readonly RulesetPublisher $publisher) {}

    public function update(
        UpdateFlagEnvironmentRequest $request,
        Flag $flag,
        Environment $environment
    ): RedirectResponse {
        if ($flag->isArchived()) {
            return back()->with('error', 'Archived flags cannot be edited.');
        }

        $state = FlagEnvironment::where('flag_id', $flag->id)
            ->where('environment_id', $environment->id)
            ->firstOrFail();

        $before = $state->stateSnapshot();
        [$variantId, $rollout] = $request->serveColumns();
        $shrinks = $request::shrinksRollout($state->fallthrough_variant_id, $state->fallthrough_rollout, $rollout);

        try {
            $this->publisher->publish($environment, function () use ($request, $state, $before, $flag, $environment, $variantId, $rollout) {
                $state->update([
                    'enabled' => $request->boolean('enabled'),
                    'off_variant_id' => $request->integer('off_variant_id'),
                    'fallthrough_variant_id' => $variantId,
                    'fallthrough_rollout' => $rollout,
                ]);

                AuditLog::record(
                    'environment.state_changed',
                    $before,
                    $state->stateSnapshot(),
                    $flag,
                    $environment
                );
            });
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not save the environment state. Please try again.');
        }

        Log::info('environment.state_changed', [
            'flag_id' => $flag->id,
            'key' => $flag->key,
            'environment' => $environment->name,
            'enabled' => $state->enabled,
        ]);

        $redirect = redirect("/flags/{$flag->key}/edit?env=".urlencode($environment->name))
            ->with('status', "State saved for {$environment->name}.");

        return $shrinks
            ? $redirect->with('warning', 'This rollout lowers a variant\'s weight, so some users already '
                .'assigned to that variant move to another one. Raising weights never reshuffles users.')
            : $redirect;
    }

    /** Kill switch: wins over everything and publishes before the redirect returns. */
    public function kill(Flag $flag, Environment $environment): RedirectResponse
    {
        return $this->setKilled($flag, $environment, true);
    }

    public function restore(Flag $flag, Environment $environment): RedirectResponse
    {
        return $this->setKilled($flag, $environment, false);
    }

    private function setKilled(Flag $flag, Environment $environment, bool $killed): RedirectResponse
    {
        if ($flag->isArchived()) {
            return back()->with('error', 'Archived flags cannot be edited.');
        }

        $state = FlagEnvironment::where('flag_id', $flag->id)
            ->where('environment_id', $environment->id)
            ->firstOrFail();

        if ($state->killed === $killed) {
            return back()->with('status', $killed ? 'Already killed.' : 'Not killed.');
        }

        $before = $state->stateSnapshot();

        try {
            $this->publisher->publish($environment, function (array $versions) use ($state, $before, $flag, $environment, $killed) {
                $state->update(['killed' => $killed]);

                AuditLog::record(
                    $killed ? 'flag.killed' : 'flag.restored',
                    $before,
                    $state->stateSnapshot(),
                    $flag,
                    $environment,
                    $versions[$environment->id] ?? null,
                );
            });
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not publish the change. Please try again.');
        }

        Log::warning($killed ? 'flag.killed' : 'flag.restored', ['key' => $flag->key, 'environment' => $environment->name]);

        return back()->with('status', $killed
            ? "{$flag->key} is killed in {$environment->name}: every evaluation now serves the off variant."
            : "{$flag->key} is restored in {$environment->name}.");
    }
}
