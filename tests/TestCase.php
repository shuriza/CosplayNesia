<?php

namespace Tests;

use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function checkoutPayload(array $payload): array
    {
        $payload = array_replace_recursive([
            'recipient' => [
                'name' => 'Test Recipient',
                'phone' => '081234567890',
                'email' => 'recipient@example.test',
            ],
            'address' => [
                'line1' => 'Jl. Test Nomor 1',
                'line2' => null,
                'city' => 'Jakarta',
                'province' => 'DKI Jakarta',
                'postal_code' => '12345',
            ],
            'handoff_note' => null,
        ], $payload);
        foreach ($payload['items'] ?? [] as $index => $item) {
            if (isset($item['variant_id']) || ! isset($item['id'])) {
                continue;
            }
            $variantId = ProductVariant::query()
                ->where('product_id', $item['id'])
                ->orderByDesc('is_default')
                ->orderBy('position')
                ->value('id');
            if ($variantId !== null) {
                $payload['items'][$index]['variant_id'] = $variantId;
            }
        }

        return $payload;
    }
}
