<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [User::ROLE_BUYER, User::ROLE_SELLER], true);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => trim((string) $this->input('description')),
            'city' => trim((string) $this->input('city')),
            'legal_name' => trim((string) $this->input('legal_name')),
            'identity_number' => preg_replace('/\D+/', '', (string) $this->input('identity_number')),
            'operating_hours' => trim((string) $this->input('operating_hours')),
            'policy' => trim((string) $this->input('policy')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'city' => ['required', 'string', 'min:2', 'max:80'],
            'legal_name' => ['required', 'string', 'min:3', 'max:120'],
            'identity_number' => ['required', 'digits_between:12,24'],
            'operating_hours' => ['nullable', 'string', 'max:200'],
            'policy' => ['nullable', 'string', 'max:2000'],
            'logo_url' => ['nullable', 'url:http,https', 'max:2048'],
            'banner_url' => ['nullable', 'url:http,https', 'max:2048'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
