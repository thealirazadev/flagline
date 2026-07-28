<?php

namespace App\Http\Requests;

use App\Models\Flag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'regex:'.Flag::KEY_PATTERN, 'unique:flags,key'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', 'in:'.Flag::TYPE_BOOLEAN.','.Flag::TYPE_STRING],
            'variants' => ['array', 'max:20'],
            'variants.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'The key must start with a letter or digit and use only lowercase letters, digits, underscores, and hyphens.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('type') !== Flag::TYPE_STRING) {
                return;
            }

            $values = $this->variantValues();

            if (count($values) < 2) {
                $validator->errors()->add('variants', 'A string flag needs at least 2 variant values.');

                return;
            }

            if (count($values) !== count(array_unique($values))) {
                $validator->errors()->add('variants', 'Variant values must be distinct.');
            }
        });
    }

    /**
     * Submitted variant values, trimmed, with the empty rows the round-trip
     * form leaves behind dropped.
     *
     * @return list<string>
     */
    public function variantValues(): array
    {
        $values = array_map(
            fn ($value) => trim((string) $value),
            (array) $this->input('variants', [])
        );

        return array_values(array_filter($values, fn (string $value) => $value !== ''));
    }
}
