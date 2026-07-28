@extends('layouts.app')

@section('title', 'New flag')

@section('content')
    <h1>New flag</h1>

    <div class="card">
        <form method="POST" action="{{ url('/flags') }}?env={{ urlencode($environment->name) }}">
            @csrf

            <div class="field">
                <label for="key">Key</label>
                <input id="key" name="key" type="text" class="mono" value="{{ old('key', request('key')) }}" required
                       maxlength="100">
                <p class="hint">
                    Lowercase letters, digits, underscores, and hyphens; must start with a letter or digit.
                    The key is permanent: SDKs and every published ruleset reference it.
                </p>
                @error('key')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" type="text" value="{{ old('name', request('name')) }}" required
                       maxlength="255">
                @error('name')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" maxlength="2000">{{ old('description', request('description')) }}</textarea>
                @error('description')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <fieldset class="field">
                <legend>Type</legend>
                <p class="hint">The type is permanent. Boolean flags get the variants true and false automatically.</p>
                <div class="checkbox">
                    <input id="type-boolean" name="type" type="radio" value="boolean"
                           @checked(old('type', request('type', 'boolean')) === 'boolean')>
                    <label for="type-boolean">boolean</label>
                </div>
                <div class="checkbox">
                    <input id="type-string" name="type" type="radio" value="string"
                           @checked(old('type', request('type')) === 'string')>
                    <label for="type-string">string (multivariate)</label>
                </div>
                @error('type')
                    <p class="error">{{ $message }}</p>
                @enderror
            </fieldset>

            <fieldset class="field">
                <legend>String variants</legend>
                <p class="hint">
                    Used only when the type is string: 2 to 20 distinct values. Order is permanent because the
                    published document references variants by index.
                </p>
                @php $rows = old('variants', $variants); @endphp
                @foreach ($rows as $index => $value)
                    <div class="field">
                        <label for="variant-{{ $index }}">Variant {{ $index + 1 }}</label>
                        <div class="actions">
                            <input id="variant-{{ $index }}" name="variants[]" type="text" value="{{ $value }}"
                                   maxlength="255">
                            @if (count($rows) > 2)
                                <button type="submit" class="btn btn-secondary" formmethod="get"
                                        formaction="{{ url('/flags/create') }}" name="remove" value="{{ $index }}">
                                    Remove
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
                @error('variants')
                    <p class="error">{{ $message }}</p>
                @enderror
                @error('variants.*')
                    <p class="error">{{ $message }}</p>
                @enderror
                <button type="submit" class="btn btn-secondary" formmethod="get"
                        formaction="{{ url('/flags/create') }}" name="action" value="add">
                    Add variant
                </button>
            </fieldset>

            <div class="actions">
                <button type="submit" class="btn">Create flag</button>
                <a class="btn btn-secondary" href="{{ url('/flags') }}?env={{ urlencode($environment->name) }}">Cancel</a>
            </div>
        </form>
    </div>
@endsection
