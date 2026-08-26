<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return ! $product instanceof Product || $product->seller_id === $this->user()?->id;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:120'],
            'series' => ['nullable', 'string', 'max:100'],
            'category' => [$presence, Rule::in(['Anime', 'Game', 'VTuber', 'Aksesoris'])],
            'price' => [$presence, 'integer', 'min:1', 'max:100000000'],
            'type' => [$presence, Rule::in(['Sewa', 'Beli'])],
            'size' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:80'],
            'image' => ['nullable', 'url:http,https', 'max:2048'],
            'stock' => [$presence, 'integer', 'min:0', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
