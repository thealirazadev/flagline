<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFlagEnvironmentRequest extends FormRequest
{
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
            'fallthrough_variant_id' => ['required', 'integer', $belongsToFlag],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'off_variant_id.exists' => 'Choose a variant that belongs to this flag.',
            'fallthrough_variant_id.exists' => 'Choose a variant that belongs to this flag.',
        ];
    }
}
