<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KitaYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSystemAdministrator() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on'], 'status' => ['required', 'in:active,archived']];
    }
}
