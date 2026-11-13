<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveRuleRequest;
use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\Flag;
use App\Models\FlagEnvironment;
use App\Models\Rule;
use App\Support\RulesetPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class RuleController extends Controller
{
    private const GONE = 'That rule no longer exists; it may have been deleted in another tab.';

    private const SHRINK_WARNING = 'This rollout lowers a variant\'s weight, so some users already '
        .'assigned to that variant move to another one. Raising weights never reshuffles users.';

    public function __construct(private readonly RulesetPublisher $publisher) {}

    public function store(Request $request, Flag $flag, Environment $environment): RedirectResponse
    {
        if ($roundTrip = $this->clauseRoundTrip($request)) {
            return $roundTrip;
        }
        if ($flag->isArchived()) {
            return back()->with('error', 'Archived flags cannot be edited.');
        }

        $input = app(SaveRuleRequest::class);
        [$variantId, $rollout] = $input->serveColumns();
        $state = FlagEnvironment::where('flag_id', $flag->id)
            ->where('environment_id', $environment->id)
            ->firstOrFail();

        $rule = $this->publish($environment, $flag, function () use ($input, $state, $variantId, $rollout, $flag, $environment): Rule {
            $rule = Rule::create([
                'flag_environment_id' => $state->id,
                'priority' => (int) $state->rules()->max('priority') + ($state->rules()->exists() ? 1 : 0),
                'description' => $input->validated('description'),
                'serve_variant_id' => $variantId,
                'serve_rollout' => $rollout,
            ]);
            $rule->clauses()->createMany($input->clauseRows());

            AuditLog::record('rule.created', null, $rule->load('clauses')->snapshot(), $flag, $environment);

            return $rule;
        });

        if ($rule === null) {
            return back()->withInput()->with('error', 'Could not save the rule. Please try again.');
        }

        return $this->backToFlag($flag, $environment)->with('status', 'Rule added.');
    }

    public function update(Request $request, int $rule): RedirectResponse
    {
        $model = Rule::with('flagEnvironment.flag', 'flagEnvironment.environment', 'clauses')->find($rule);
        if ($model === null) {
            return back()->with('error', self::GONE);
        }
        if ($roundTrip = $this->clauseRoundTrip($request)) {
            return $roundTrip;
        }

        [$flag, $environment] = [$model->flagEnvironment->flag, $model->flagEnvironment->environment];
        if ($flag->isArchived()) {
            return back()->with('error', 'Archived flags cannot be edited.');
        }

        $input = app(SaveRuleRequest::class);
        [$variantId, $rollout] = $input->serveColumns();
        $before = $model->snapshot();
        $shrinks = $input::shrinksRollout($model->serve_variant_id, $model->serve_rollout, $rollout);

        $saved = $this->publish($environment, $flag, function () use ($input, $model, $variantId, $rollout, $before, $flag, $environment): Rule {
            $model->update([
                'description' => $input->validated('description'),
                'serve_variant_id' => $variantId,
                'serve_rollout' => $rollout,
            ]);
            $model->clauses()->delete();
            $model->clauses()->createMany($input->clauseRows());

            AuditLog::record('rule.updated', $before, $model->load('clauses')->snapshot(), $flag, $environment);

            return $model;
        });

        if ($saved === null) {
            return back()->withInput()->with('error', 'Could not save the rule. Please try again.');
        }

        $redirect = $this->backToFlag($flag, $environment)->with('status', 'Rule saved.');

        return $shrinks ? $redirect->with('warning', self::SHRINK_WARNING) : $redirect;
    }

    public function move(Request $request, int $rule): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];
        $model = Rule::with('flagEnvironment.flag', 'flagEnvironment.environment', 'clauses')->find($rule);
        if ($model === null) {
            return back()->with('error', self::GONE);
        }

        [$flag, $environment] = [$model->flagEnvironment->flag, $model->flagEnvironment->environment];
        $neighbour = Rule::where('flag_environment_id', $model->flag_environment_id)
            ->where('priority', $direction === 'up' ? $model->priority - 1 : $model->priority + 1)
            ->first();
        if ($neighbour === null) {
            return $this->backToFlag($flag, $environment);
        }

        $before = $model->snapshot();
        $saved = $this->publish($environment, $flag, function () use ($model, $neighbour, $before, $flag, $environment): Rule {
            // Park one row outside the range so the unique (env, priority) index never collides.
            [$from, $to] = [$model->priority, $neighbour->priority];
            $model->update(['priority' => Rule::where('flag_environment_id', $model->flag_environment_id)->max('priority') + 1]);
            $neighbour->update(['priority' => $from]);
            $model->update(['priority' => $to]);

            AuditLog::record('rule.updated', $before, $model->snapshot(), $flag, $environment);

            return $model;
        });

        return $saved === null
            ? back()->with('error', 'Could not move the rule. Please try again.')
            : $this->backToFlag($flag, $environment)->with('status', 'Rule moved.');
    }

    public function destroy(int $rule): RedirectResponse
    {
        $model = Rule::with('flagEnvironment.flag', 'flagEnvironment.environment', 'clauses')->find($rule);
        if ($model === null) {
            return back()->with('error', self::GONE);
        }

        [$flag, $environment] = [$model->flagEnvironment->flag, $model->flagEnvironment->environment];
        $before = $model->snapshot();

        $deleted = $this->publish($environment, $flag, function () use ($model, $before, $flag, $environment): bool {
            $model->delete();
            // Close the gap one row at a time, lowest first, so the unique index never collides.
            Rule::where('flag_environment_id', $model->flag_environment_id)
                ->where('priority', '>', $model->priority)
                ->orderBy('priority')
                ->get()
                ->each(fn (Rule $rest) => $rest->update(['priority' => $rest->priority - 1]));

            AuditLog::record('rule.deleted', $before, null, $flag, $environment);

            return true;
        });

        return $deleted === null
            ? back()->with('error', 'Could not delete the rule. Please try again.')
            : $this->backToFlag($flag, $environment)->with('status', 'Rule deleted.');
    }

    /**
     * No-JS clause rows: "Add clause" and "Remove" buttons re-render the form with the
     * edited rows and save nothing.
     */
    private function clauseRoundTrip(Request $request): ?RedirectResponse
    {
        $action = (string) $request->input('clause_action', '');
        if ($action === '') {
            return null;
        }

        $clauses = array_values((array) $request->input('clauses', []));
        if ($action === 'add' && count($clauses) < SaveRuleRequest::MAX_CLAUSES) {
            $clauses[] = ['attribute' => '', 'operator' => 'eq', 'values' => ''];
        } elseif (str_starts_with($action, 'remove:') && count($clauses) > 1) {
            unset($clauses[(int) substr($action, 7)]);
        }

        return back()->withInput(['clauses' => array_values($clauses)] + $request->except('clauses', 'clause_action'));
    }

    private function publish(Environment $environment, Flag $flag, \Closure $mutation): mixed
    {
        try {
            $result = $this->publisher->publish($environment, $mutation);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        Log::info('rule.changed', ['flag' => $flag->key, 'environment' => $environment->name]);

        return $result;
    }

    private function backToFlag(Flag $flag, Environment $environment): RedirectResponse
    {
        return redirect("/flags/{$flag->key}/edit?env=".urlencode($environment->name));
    }
}
