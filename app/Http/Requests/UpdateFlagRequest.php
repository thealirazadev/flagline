<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The key and the type are immutable after creation: SDKs and every stored
     * ruleset document reference them.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
