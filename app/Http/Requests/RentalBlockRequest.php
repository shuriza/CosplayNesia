<?php

namespace App\Http\Requests;

use App\Models\Product;

class RentalBlockRequest extends AvailabilityRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && $product->seller_id === $this->user()?->id;
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'quantity' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', 'min:1', 'max:10000'],
            'reason' => ['nullable', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
