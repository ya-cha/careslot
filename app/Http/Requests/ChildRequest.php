<?php

namespace App\Http\Requests;

use App\Models\Child;
use App\Models\EmergencyAllocation;
use App\Models\ParentContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->route('child') instanceof Child) {
            abort_unless($this->user()?->managesGroup($this->route('child')->group_id), 404);
        }

        return $this->user()?->can('viewAny', EmergencyAllocation::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $contacts = $this->input('contacts', []);
        if (is_array($contacts)) {
            $this->merge(['contacts' => array_values(array_filter($contacts, fn ($contact) => ! is_array($contact) || ! empty($contact['id']) || ! empty($contact['name']) || ! empty($contact['email'])))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'group_id' => ['required', Rule::exists('care_groups', 'id')->when($this->user()?->group_id !== null, fn ($rule) => $rule->where('id', $this->user()->group_id))],
            'kita_year_id' => ['required', 'exists:kita_years,id'], 'family_id' => ['nullable', Rule::exists('families', 'id')->where('group_id', $this->integer('group_id'))],
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'active' => ['required', 'boolean'],
            'family_name' => ['required_without:family_id', 'nullable', 'string', 'max:150'],
            'contacts' => [Rule::requiredIf(! $this->input('family_id')), 'array'],
            'contacts.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('parent_contacts', 'id')->where('family_id', $this->integer('family_id'))],
            'contacts.*.name' => ['required', 'string', 'max:150'], 'contacts.*.email' => ['required', 'email', 'max:255', 'distinct'],
            'rules' => ['required', 'array', 'size:5'], 'rules.*.weekday' => ['required', 'integer', 'between:1,5', 'distinct'],
            'rules.*.pattern' => ['required', 'in:always,never,even,odd'],
            'rules.*.valid_from' => ['required', 'date'], 'rules.*.valid_until' => ['nullable', 'date', 'after_or_equal:rules.*.valid_from'],
        ];

        foreach (is_array($this->input('contacts')) ? $this->input('contacts') : [] as $index => $contact) {
            $existing = is_array($contact) && is_scalar($contact['id'] ?? null)
                ? ParentContact::where('family_id', $this->integer('family_id'))->find($contact['id']) : null;
            $rules['contacts.'.$index.'.email'] = ['required', 'email', 'max:255', 'distinct', Rule::unique('parent_contacts', 'email')->where('family_id', $this->integer('family_id'))->ignore($existing)];
        }

        return $rules;
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (collect($this->array('contacts'))->filter(fn ($contact) => empty($contact['id']))->count() > 10) {
                $validator->errors()->add('contacts', 'Bitte höchstens zehn neue Kontakte auf einmal ergänzen.');
            }
            if ($this->route('child') instanceof Child && (int) $this->input('kita_year_id') !== $this->route('child')->kita_year_id) {
                $validator->errors()->add('kita_year_id', 'Das Kita-Jahr eines bestehenden Kindes kann nicht geändert werden. Für das neue Jahr einen neuen Eintrag anlegen.');
            }
        }];
    }
}
