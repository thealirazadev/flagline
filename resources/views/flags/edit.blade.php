@extends('layouts.app')

@section('title', 'Flag '.$flag->key)

@section('content')
    <h1 class="mono">{{ $flag->key }}</h1>

    <nav class="switcher" aria-label="Environment">
        @foreach ($environments as $option)
            <a href="{{ url("/flags/{$flag->key}/edit") }}?env={{ urlencode($option->name) }}"
               @if ($option->is($environment)) aria-current="page" @endif>{{ $option->name }}</a>
        @endforeach
    </nav>

    @if ($flag->isArchived())
        <div class="banner-danger">
            This flag is archived. It no longer appears in published rulesets and its configuration is read-only.
        </div>
    @endif

    @if ($state->killed && ! $flag->isArchived())
        <div class="banner-danger" role="alert">
            Killed in {{ $environment->name }}: every evaluation serves the off variant until you restore it.
        </div>
    @endif

    <div class="card">
        <h2>State in {{ $environment->name }}</h2>
        @include('flags._kill')
        <p class="muted small">
            Every environment holds its own state. Changes here never affect the other environments.
        </p>

        <form method="POST"
              action="{{ url("/flags/{$flag->key}/environments/{$environment->name}") }}?env={{ urlencode($environment->name) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="form_id" value="state">

            <div class="field checkbox">
                <input id="enabled" name="enabled" type="checkbox" value="1"
                       @checked(old('enabled', $state->enabled)) @disabled($flag->isArchived())>
                <label for="enabled">Enabled</label>
            </div>
            <p class="hint">When disabled, every evaluation serves the off variant with reason off.</p>

            <div class="field">
                <label for="off_variant_id">Off variant</label>
                <select id="off_variant_id" name="off_variant_id" @disabled($flag->isArchived())>
                    @foreach ($flag->variants as $index => $variant)
                        <option value="{{ $variant->id }}"
                                @selected(old('off_variant_id', $state->off_variant_id) == $variant->id)>
                            {{ $index }}: {{ $variant->value }}
                        </option>
                    @endforeach
                </select>
                <p class="hint">Served when the flag is disabled or killed.</p>
                @error('off_variant_id')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            @include('flags._serve', [
                'formId' => 'state',
                'prefix' => 'fallthrough',
                'legend' => 'Fallthrough: served when the flag is enabled and no rule matches',
                'current' => [$state->fallthrough_variant_id, $state->fallthrough_rollout],
                'readonly' => $flag->isArchived(),
            ])

            @unless ($flag->isArchived())
                <button type="submit" class="btn">Save state</button>
            @endunless
        </form>
    </div>

    <div class="card">
        <h2>Targeting rules in {{ $environment->name }}</h2>
        <p class="muted small">Rules run top to bottom; the first rule whose clauses all match decides the variant.</p>

        @forelse ($rules as $rule)
            <section class="rule" aria-labelledby="rule-{{ $rule->id }}-title">
                <h3 id="rule-{{ $rule->id }}-title">Rule {{ $loop->iteration }}@if ($rule->description): {{ $rule->description }}@endif</h3>

                <form method="POST" action="{{ url("/rules/{$rule->id}") }}?env={{ urlencode($environment->name) }}">
                    @method('PUT')
                    @include('flags._rule_form', ['formId' => 'rule-'.$rule->id, 'readonly' => $flag->isArchived()])
                    @unless ($flag->isArchived())
                        <button type="submit" class="btn">Save rule</button>
                    @endunless
                </form>

                @unless ($flag->isArchived())
                    <div class="row">
                        @unless ($loop->first)
                            <form method="POST" action="{{ url("/rules/{$rule->id}/move") }}">
                                @csrf
                                <button type="submit" class="btn btn-secondary" name="direction" value="up">Move up</button>
                            </form>
                        @endunless
                        @unless ($loop->last)
                            <form method="POST" action="{{ url("/rules/{$rule->id}/move") }}">
                                @csrf
                                <button type="submit" class="btn btn-secondary" name="direction" value="down">Move down</button>
                            </form>
                        @endunless
                        <details>
                            <summary>Delete rule</summary>
                            <form method="POST" action="{{ url("/rules/{$rule->id}") }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger">Yes, delete rule {{ $loop->iteration }}</button>
                            </form>
                        </details>
                    </div>
                @endunless
            </section>
        @empty
            <p class="muted">No rules yet: everyone gets the fallthrough serve.</p>
        @endforelse

        @unless ($flag->isArchived())
            <details @if (old('form_id') === 'new') open @endif>
                <summary>Add a rule</summary>
                <form method="POST" action="{{ url("/flags/{$flag->key}/environments/{$environment->name}/rules") }}?env={{ urlencode($environment->name) }}">
                    @include('flags._rule_form', ['formId' => 'new', 'rule' => null, 'readonly' => false])
                    <button type="submit" class="btn">Add rule</button>
                </form>
            </details>
        @endunless
    </div>

    <div class="card">
        <h2>Details</h2>

        <form method="POST" action="{{ url("/flags/{$flag->key}") }}?env={{ urlencode($environment->name) }}">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" type="text" value="{{ old('name', $flag->name) }}" required
                       maxlength="255" @disabled($flag->isArchived())>
                @error('name')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" maxlength="2000"
                          @disabled($flag->isArchived())>{{ old('description', $flag->description) }}</textarea>
                @error('description')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <dl class="small muted">
                <dt>Type</dt>
                <dd><span class="badge badge-type">{{ $flag->type }}</span> (permanent)</dd>
                <dt>Variants</dt>
                <dd class="mono">
                    @foreach ($flag->variants as $index => $variant)
                        {{ $index }}: {{ $variant->value }}@if (! $loop->last), @endif
                    @endforeach
                </dd>
            </dl>

            @unless ($flag->isArchived())
                <button type="submit" class="btn">Save details</button>
            @endunless
        </form>
    </div>

    @unless ($flag->isArchived())
        <div class="card">
            <h2>Archive</h2>
            <p class="muted small">
                Archiving drops the flag from newly published rulesets. History and the audit trail are kept.
            </p>
            <details>
                <summary>Archive this flag</summary>
                <form method="POST"
                      action="{{ url("/flags/{$flag->key}/archive") }}?env={{ urlencode($environment->name) }}">
                    @csrf
                    <p class="small">This cannot be undone from the dashboard.</p>
                    <button type="submit" class="btn btn-danger">Yes, archive {{ $flag->key }}</button>
                </form>
            </details>
        </div>
    @endunless
@endsection
