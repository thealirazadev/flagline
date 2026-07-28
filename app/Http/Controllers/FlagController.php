<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFlagRequest;
use App\Http\Requests\UpdateFlagRequest;
use App\Models\AuditLog;
use App\Models\Environment;
use App\Models\Flag;
use App\Models\FlagEnvironment;
use App\Models\Variant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class FlagController extends Controller
{
    public function index(Request $request): View
    {
        $environment = $this->selectedEnvironment($request);
        $archived = $request->boolean('archived');

        $flags = Flag::query()
            ->when(! $archived, fn ($query) => $query->active())
            ->when($archived, fn ($query) => $query->whereNotNull('archived_at'))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->toString().'%';
                $query->where(fn ($inner) => $inner->where('key', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->with(['flagEnvironments' => fn ($query) => $query->where('environment_id', $environment->id)])
            ->orderBy('key')
            ->paginate(25)
            ->withQueryString();

        return view('flags.index', [
            'flags' => $flags,
            'environment' => $environment,
            'environments' => $this->environments(),
            'archived' => $archived,
        ]);
    }

    /**
     * The variant rows are a round-trip form: the add and remove buttons submit
     * this same GET route so the operator keeps everything already typed.
     */
    public function create(Request $request): View
    {
        $variants = array_map(fn ($value) => (string) $value, (array) $request->query('variants', ['', '']));

        if ($request->query('action') === 'add' && count($variants) < 20) {
            $variants[] = '';
        }

        $remove = $request->query('remove');
        if ($remove !== null && count($variants) > 2) {
            unset($variants[(int) $remove]);
        }

        return view('flags.create', [
            'environment' => $this->selectedEnvironment($request),
            'environments' => $this->environments(),
            'variants' => array_values($variants),
        ]);
    }

    public function store(StoreFlagRequest $request): RedirectResponse
    {
        $values = $request->input('type') === Flag::TYPE_BOOLEAN
            ? ['true', 'false']
            : $request->variantValues();

        try {
            $flag = DB::transaction(function () use ($request, $values) {
                $flag = Flag::create($request->safe()->only(['key', 'name', 'description', 'type']));

                foreach ($values as $index => $value) {
                    Variant::create([
                        'flag_id' => $flag->id,
                        'value' => $value,
                        'sort_order' => $index,
                    ]);
                }

                $this->createEnvironmentState($flag);

                AuditLog::record('flag.created', null, $this->snapshot($flag), $flag);

                return $flag;
            });
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not create the flag. Please try again.');
        }

        Log::info('flag.created', ['flag_id' => $flag->id, 'key' => $flag->key, 'type' => $flag->type]);

        return redirect("/flags/{$flag->key}/edit")->with('status', "Flag {$flag->key} created.");
    }

    public function edit(Request $request, Flag $flag): View
    {
        $environment = $this->selectedEnvironment($request);

        return view('flags.edit', [
            'flag' => $flag->load('variants'),
            'environment' => $environment,
            'environments' => $this->environments(),
            'state' => $this->stateFor($flag, $environment),
        ]);
    }

    public function update(UpdateFlagRequest $request, Flag $flag): RedirectResponse
    {
        if ($flag->isArchived()) {
            return back()->with('error', 'Archived flags cannot be edited.');
        }

        $before = $this->snapshot($flag);

        try {
            DB::transaction(function () use ($request, $flag, $before) {
                $flag->update($request->validated());

                AuditLog::record('flag.updated', $before, $this->snapshot($flag->fresh()), $flag);
            });
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not save the flag. Please try again.');
        }

        Log::info('flag.updated', ['flag_id' => $flag->id, 'key' => $flag->key]);

        return redirect($this->editUrl($request, $flag))->with('status', 'Flag updated.');
    }

    public function archive(Request $request, Flag $flag): RedirectResponse
    {
        if ($flag->isArchived()) {
            return back()->with('error', 'That flag is already archived.');
        }

        $before = $this->snapshot($flag);

        try {
            DB::transaction(function () use ($flag, $before) {
                // archived_at stays out of $fillable so no request payload can set it.
                $flag->archived_at = now();
                $flag->save();

                AuditLog::record('flag.archived', $before, null, $flag);
            });
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not archive the flag. Please try again.');
        }

        Log::info('flag.archived', ['flag_id' => $flag->id, 'key' => $flag->key]);

        return redirect('/flags?env='.urlencode((string) $request->query('env', 'production')))
            ->with('status', "Flag {$flag->key} archived.");
    }

    /**
     * The flag-level shape stored in audit before/after snapshots.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Flag $flag): array
    {
        return [
            'key' => $flag->key,
            'name' => $flag->name,
            'description' => $flag->description,
            'type' => $flag->type,
            'variants' => $flag->variants()->pluck('value')->all(),
        ];
    }

    /**
     * One state row per environment, created with the flag. New flags are off
     * everywhere; boolean flags serve false when off and true on fallthrough.
     */
    private function createEnvironmentState(Flag $flag): void
    {
        $variants = $flag->variants()->get();
        $offVariant = $flag->isBoolean() ? $variants->firstWhere('value', 'false') : $variants->first();
        $fallthroughVariant = $flag->isBoolean() ? $variants->firstWhere('value', 'true') : $variants->first();

        foreach (Environment::all() as $environment) {
            FlagEnvironment::create([
                'flag_id' => $flag->id,
                'environment_id' => $environment->id,
                'enabled' => false,
                'killed' => false,
                'off_variant_id' => $offVariant->id,
                'fallthrough_variant_id' => $fallthroughVariant->id,
            ]);
        }
    }

    private function stateFor(Flag $flag, Environment $environment): FlagEnvironment
    {
        return FlagEnvironment::where('flag_id', $flag->id)
            ->where('environment_id', $environment->id)
            ->firstOrFail();
    }

    private function editUrl(Request $request, Flag $flag): string
    {
        return "/flags/{$flag->key}/edit?env=".urlencode((string) $request->query('env', 'production'));
    }
}
