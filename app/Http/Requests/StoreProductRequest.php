<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        if ($product instanceof Product && $product->seller_id !== $this->user()?->id) {
            return false;
        }

        return $this->user()?->store?->isApproved() === true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('post') ? 'required' : 'sometimes';
        $product = $this->route('product');
        $type = $this->input('type', $product instanceof Product ? $product->type : null);
        $pricingRequired = $this->isMethod('post') || $this->hasAny([
            'type', 'sale_price', 'rental_price_per_day', 'rental_deposit', 'rental_min_days', 'rental_max_days',
        ]);

        return [
            'name' => [$presence, 'string', 'max:120'],
            'series' => ['nullable', 'string', 'max:100'],
            'category' => [$presence, Rule::in(['Anime', 'Game', 'VTuber', 'Aksesoris'])],
            'category_attributes' => ['sometimes', 'array:character,material,fit'],
            'category_attributes.character' => ['nullable', 'string', 'max:100'],
            'category_attributes.material' => ['nullable', 'string', 'max:100'],
            'category_attributes.fit' => ['nullable', Rule::in(['women', 'men', 'unisex', 'kids'])],
            'costume_includes' => ['sometimes', 'array', 'max:30'],
            'costume_includes.*' => ['required', 'string', 'distinct', 'max:100'],
            'type' => [$presence, Rule::in(['Sewa', 'Beli'])],
            'sale_price' => [
                Rule::requiredIf($pricingRequired && $type === Product::TYPE_SALE),
                Rule::prohibitedIf($type === Product::TYPE_RENTAL),
                'nullable', 'integer', 'min:1', 'max:100000000',
            ],
            'rental_price_per_day' => [
                Rule::requiredIf($pricingRequired && $type === Product::TYPE_RENTAL),
                Rule::prohibitedIf($type === Product::TYPE_SALE),
                'nullable', 'integer', 'min:1', 'max:100000000',
            ],
            'rental_deposit' => [Rule::requiredIf($pricingRequired && $type === Product::TYPE_RENTAL), Rule::prohibitedIf($type === Product::TYPE_SALE), 'nullable', 'integer', 'min:0', 'max:100000000'],
            'rental_min_days' => [Rule::requiredIf($pricingRequired && $type === Product::TYPE_RENTAL), Rule::prohibitedIf($type === Product::TYPE_SALE), 'nullable', 'integer', 'min:1', 'max:30'],
            'rental_max_days' => [Rule::requiredIf($pricingRequired && $type === Product::TYPE_RENTAL), Rule::prohibitedIf($type === Product::TYPE_SALE), 'nullable', 'integer', 'min:1', 'max:30'],
            'size' => ['nullable', 'string', 'max:30'],
            'measurements' => ['sometimes', 'array:chest,waist,hips,height'],
            'measurements.chest' => ['nullable', 'numeric', 'min:1', 'max:400'],
            'measurements.waist' => ['nullable', 'numeric', 'min:1', 'max:400'],
            'measurements.hips' => ['nullable', 'numeric', 'min:1', 'max:400'],
            'measurements.height' => ['nullable', 'numeric', 'min:1', 'max:300'],
            'care_instructions' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:80'],
            'stock' => [$presence, 'integer', 'min:0', 'max:10000'],
            'images' => ['sometimes', 'array', 'max:8'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('rental_min_days') && $this->filled('rental_max_days')
                && (int) $this->input('rental_max_days') < (int) $this->input('rental_min_days')) {
                $validator->errors()->add('rental_max_days', 'Durasi maksimum harus sama atau lebih besar dari durasi minimum.');
            }
        }];
    }
}
