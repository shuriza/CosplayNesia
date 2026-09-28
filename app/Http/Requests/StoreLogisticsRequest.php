<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreLogisticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_SELLER;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'origin_address_line1' => trim((string) $this->input('origin_address_line1')),
            'origin_address_line2' => trim((string) $this->input('origin_address_line2')) ?: null,
            'origin_province' => trim((string) $this->input('origin_province')),
            'origin_postal_code' => preg_replace('/\D+/', '', (string) $this->input('origin_postal_code')),
            'pickup_enabled' => $this->boolean('pickup_enabled'),
            'pickup_instructions' => trim((string) $this->input('pickup_instructions')) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'origin_address_line1' => ['required', 'string', 'min:5', 'max:255'],
            'origin_address_line2' => ['nullable', 'string', 'max:255'],
            'origin_province' => ['required', 'string', 'min:2', 'max:80'],
            'origin_postal_code' => ['required', 'digits:5'],
            'pickup_enabled' => ['required', 'boolean'],
            'pickup_instructions' => ['nullable', 'required_if:pickup_enabled,true', 'string', 'max:500'],
        ];
    }
}
