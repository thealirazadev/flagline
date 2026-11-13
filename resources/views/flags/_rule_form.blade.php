{{-- One rule's fields: description, clause rows, serve. $rule is null for the add form. --}}
@php
    $mine = old('form_id') === $formId;
    $clauses = $mine
        ? array_values((array) old('clauses', []))
        : ($rule ? $rule->clauses->map(fn ($c) => ['attribute' => $c->attribute, 'operator' => $c->operator, 'values' => implode("\n", $c->values)])->all()
                 : [['attribute' => '', 'operator' => 'in', 'values' => '']]);
@endphp
@csrf
<input type="hidden" name="form_id" value="{{ $formId }}">

<div class="field">
    <label for="{{ $formId }}-description">Description</label>
    <input id="{{ $formId }}-description" type="text" name="description" maxlength="255"
           value="{{ $mine ? old('description') : $rule?->description }}" @disabled($readonly)>
    @if ($mine) @error('description') <p class="error">{{ $message }}</p> @enderror @endif
</div>

<fieldset>
    <legend>Clauses (all must match)</legend>
    @if ($mine) @error('clauses') <p class="error">{{ $message }}</p> @enderror @endif
    @foreach ($clauses as $i => $clause)
        <div class="clause-row">
            <div class="field">
                <label for="{{ $formId }}-c{{ $i }}-attribute">Attribute</label>
                <input id="{{ $formId }}-c{{ $i }}-attribute" type="text" name="clauses[{{ $i }}][attribute]" maxlength="100"
                       value="{{ $clause['attribute'] ?? '' }}" placeholder="plan, country, key" @disabled($readonly)>
                @if ($mine) @error("clauses.$i.attribute") <p class="error">{{ $message }}</p> @enderror @endif
            </div>
            <div class="field">
                <label for="{{ $formId }}-c{{ $i }}-operator">Operator</label>
                <select id="{{ $formId }}-c{{ $i }}-operator" name="clauses[{{ $i }}][operator]" @disabled($readonly)>
                    @foreach (\App\Models\RuleClause::OPERATORS as $operator)
                        <option value="{{ $operator }}" @selected(($clause['operator'] ?? '') === $operator)>{{ $operator }}</option>
                    @endforeach
                </select>
                @if ($mine) @error("clauses.$i.operator") <p class="error">{{ $message }}</p> @enderror @endif
            </div>
            <div class="field">
                <label for="{{ $formId }}-c{{ $i }}-values">Values (one per line)</label>
                <textarea id="{{ $formId }}-c{{ $i }}-values" name="clauses[{{ $i }}][values]" rows="2" @disabled($readonly)>{{ is_array($clause['values'] ?? '') ? implode("\n", $clause['values']) : ($clause['values'] ?? '') }}</textarea>
                @if ($mine)
                    @error("clauses.$i.values") <p class="error">{{ $message }}</p> @enderror
                    @error("clauses.$i.values.*") <p class="error">{{ $message }}</p> @enderror
                @endif
            </div>
            @if (! $readonly && count($clauses) > 1)
                <button type="submit" class="btn btn-secondary" name="clause_action" value="remove:{{ $i }}">Remove clause</button>
            @endif
        </div>
    @endforeach
    @unless ($readonly)
        <button type="submit" class="btn btn-secondary" name="clause_action" value="add">Add clause</button>
    @endunless
</fieldset>

@include('flags._serve', [
    'prefix' => $formId,
    'legend' => 'Serve when the rule matches',
    'current' => [$rule?->serve_variant_id, $rule?->serve_rollout],
])
