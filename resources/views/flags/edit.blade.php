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
