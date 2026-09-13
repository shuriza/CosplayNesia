<?php

namespace App\Http\Requests;

use App\Models\FulfillmentMessage;
use Illuminate\Foundation\Http\FormRequest;

class StoreFulfillmentMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.FulfillmentMessage::MAX_BODY_LENGTH],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }
}
