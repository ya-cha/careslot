<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ParentResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['regular_needed' => ['nullable', 'boolean'], 'waiting_needed' => ['required', 'boolean'], 'volunteer' => ['required', 'boolean']];
    }
}
