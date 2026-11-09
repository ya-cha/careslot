<?php

namespace App\Http\Requests;

use App\Models\EmergencyAllocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmergencyDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', EmergencyAllocation::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['group_id' => ['required', Rule::exists('care_groups', 'id')->when($this->user()?->group_id !== null, fn ($rule) => $rule->where('id', $this->user()->group_id))], 'kita_year_id' => ['required', 'exists:kita_years,id'], 'date' => ['required', 'date'], 'capacity' => ['required', 'integer', 'min:0', 'max:10000'], 'starts_at' => ['nullable', 'date_format:H:i'], 'ends_at' => ['nullable', 'date_format:H:i', Rule::when($this->filled('starts_at'), ['after:starts_at'])], 'response_deadline' => ['nullable', 'date', 'after:now']];
    }
}
