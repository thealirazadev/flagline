<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesServe;
use App\Models\Flag;
use App\Models\Rule;
use App\Models\RuleClause;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\Validator;

class SaveRuleRequest extends FormRequest
{
    use ValidatesServe;

    public const MAX_CLAUSES = 20;

    public const MAX_VALUES = 100;

    public function authorize(): bool
    {
        return true;
    }

    /** Clause values arrive one per line; blank lines are dropped. */
    protected function prepareForValidation(): void
    {
        $clauses = [];
        foreach ((array) $this->input('clauses', []) as $clause) {
            if (! is_array($clause)) {
                continue;
            }
            $values = $clause['values'] ?? '';
            if (is_string($values)) {
                $values = array_values(array_filter(
                    array_map('trim', preg_split('/\R/', $values) ?: []),
                    fn (string $value) => $value !== ''
                ));
            }
            $clause['values'] = $values;
            $clauses[] = $clause;
        }

        $this->merge(['clauses' => $clauses]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:255'],
            'clauses' => ['required', 'array', 'min:1', 'max:'.self::MAX_CLAUSES],
            'clauses.*.attribute' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'clauses.*.operator' => ['required', ValidationRule::in(RuleClause::OPERATORS)],
            'clauses.*.values' => ['required', 'array', 'min:1', 'max:'.self::MAX_VALUES],
            'clauses.*.values.*' => ['string', 'max:255'],
        ] + $this->serveRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->input('clauses') as $i => $clause) {
                $operator = $clause['operator'];
                $values = $clause['values'];

                if (in_array($operator, RuleClause::SINGLE_VALUE_OPERATORS, true) && count($values) !== 1) {
                    $validator->errors()->add("clauses.{$i}.values", "The {$operator} operator takes exactly one value.");
                } elseif (str_starts_with($operator, 'semver_') && ! RuleClause::isSemver($values[0])) {
                    $validator->errors()->add("clauses.{$i}.values", "\"{$values[0]}\" is not a semantic version such as 1.2.3 or 1.2.3-beta.1.");
                }
            }

            $this->validateRollout($validator);
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'clauses.required' => 'A rule needs at least one clause.',
            'clauses.*.attribute.required' => 'Name the attribute this clause checks.',
            'clauses.*.attribute.regex' => 'Attribute names use letters, digits, dot, dash, and underscore.',
            'clauses.*.operator.in' => 'Choose an operator from the list.',
            'clauses.*.values.required' => 'Give at least one value, one per line.',
            'clauses.*.values.*.max' => 'Each value can be at most 255 characters.',
            'weights.required_if' => 'Enter a weight for each variant.',
            'serve_variant_id.in' => 'Choose a variant that belongs to this flag.',
        ];
    }

    protected function serveFlag(): Flag
    {
        $flag = $this->route('flag');
        if ($flag instanceof Flag) {
            return $flag->loadMissing('variants');
        }

        /** @var Rule $rule */
        $rule = Rule::query()->with('flagEnvironment.flag.variants')->findOrFail($this->route('rule'));

        return $rule->flagEnvironment->flag;
    }

    /**
     * Clause rows as stored.
     *
     * @return array<int, array{attribute: string, operator: string, values: array<int, string>}>
     */
    public function clauseRows(): array
    {
        return array_map(fn (array $clause) => [
            'attribute' => $clause['attribute'],
            'operator' => $clause['operator'],
            'values' => array_values($clause['values']),
        ], $this->validated('clauses'));
    }
}
