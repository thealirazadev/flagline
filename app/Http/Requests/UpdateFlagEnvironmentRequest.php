<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesServe;
use App\Models\Flag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFlagEnvironmentRequest extends FormRequest
{
    use ValidatesServe;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Variant references are constrained to the variants of this flag so a
     * forged id cannot point at another flag's variant.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $belongsToFlag = Rule::exists('variants', 'id')
            ->where('flag_id', $this->route('flag')->id);

        return [
            'enabled' => ['nullable', 'boolean'],
            'off_variant_id' => ['required', 'integer', $belongsToFlag],
        ] + $this->serveRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateRollout($validator));
    }

    protected function serveFlag(): Flag
    {
        return $this->route('flag')->loadMissing('variants');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'off_variant_id.exists' => 'Choose a variant that belongs to this flag.',
            'serve_variant_id.in' => 'Choose a variant that belongs to this flag.',
            'weights.required_if' => 'Enter a weight for each variant.',
        ];
    }
}
