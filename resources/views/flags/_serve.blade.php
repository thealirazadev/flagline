{{-- Serve editor. $prefix makes ids unique per form; $current = [variantId, rollout] when nothing was posted. --}}
@php
    $mine = old('form_id') === $formId;
    $serve = $mine ? old('serve') : ($current[1] ? 'rollout' : 'variant');
    $variantId = $mine ? old('serve_variant_id') : $current[0];
    $weights = $mine ? (array) old('weights', []) : collect($current[1] ?? [])->pluck('weight', 'variant_id')->all();
@endphp
<fieldset class="serve">
    <legend>{{ $legend }}</legend>

    <div class="field checkbox">
        <input id="{{ $prefix }}-serve-variant" type="radio" name="serve" value="variant" @checked($serve !== 'rollout') @disabled($readonly)>
        <label for="{{ $prefix }}-serve-variant">Serve one variant</label>
    </div>
    <div class="field">
        <label for="{{ $prefix }}-serve-variant-id">Variant</label>
        <select id="{{ $prefix }}-serve-variant-id" name="serve_variant_id" @disabled($readonly)>
            @foreach ($flag->variants as $index => $variant)
                <option value="{{ $variant->id }}" @selected((string) $variantId === (string) $variant->id)>{{ $index }}: {{ $variant->value }}</option>
            @endforeach
        </select>
        @if ($mine)
            @error('serve_variant_id') <p class="error">{{ $message }}</p> @enderror
        @endif
    </div>

    <div class="field checkbox">
        <input id="{{ $prefix }}-serve-rollout" type="radio" name="serve" value="rollout" @checked($serve === 'rollout') @disabled($readonly)>
        <label for="{{ $prefix }}-serve-rollout">Percentage rollout</label>
    </div>
    <p class="hint">Weights are basis points: 10000 is 100%, 2500 is 25%. They must add up to 10000.</p>
    @foreach ($flag->variants as $index => $variant)
        <div class="field inline">
            <label for="{{ $prefix }}-weight-{{ $variant->id }}">{{ $index }}: {{ $variant->value }}</label>
            <input id="{{ $prefix }}-weight-{{ $variant->id }}" type="number" name="weights[{{ $variant->id }}]"
                   min="0" max="10000" step="1" value="{{ $weights[$variant->id] ?? 0 }}" @disabled($readonly)>
        </div>
    @endforeach
    @if ($mine)
        @error('weights') <p class="error">{{ $message }}</p> @enderror
        @error('weights.*') <p class="error">{{ $message }}</p> @enderror
    @endif
</fieldset>
