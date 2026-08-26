<?php

namespace App\Http\Requests;

use App\Models\OrderFulfillment;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFulfillmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $fulfillment = $this->route('fulfillment');

        return $fulfillment instanceof OrderFulfillment
            && $fulfillment->seller_id === $this->user()?->id;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'max:24'],
        ];
    }
}
