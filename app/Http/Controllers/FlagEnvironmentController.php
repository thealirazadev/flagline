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

        try {
            $this->publisher->publish($environment, function () use ($request, $state, $before, $flag, $environment) {
                $state->update([
                    'enabled' => $request->boolean('enabled'),
                    'off_variant_id' => $request->integer('off_variant_id'),
                    'fallthrough_variant_id' => $request->integer('fallthrough_variant_id'),
                    'fallthrough_rollout' => null,
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

        return redirect("/flags/{$flag->key}/edit?env=".urlencode($environment->name))
            ->with('status', "State saved for {$environment->name}.");
    }
}
