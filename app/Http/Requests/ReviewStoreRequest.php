<?php

namespace App\Http\Requests;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canReviewStores() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['note' => trim((string) $this->input('note'))]);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([Store::STATUS_APPROVED, Store::STATUS_REJECTED, Store::STATUS_SUSPENDED])],
            'note' => ['required_if:status,rejected,suspended', 'nullable', 'string', 'max:1000'],
        ];
    }
}
