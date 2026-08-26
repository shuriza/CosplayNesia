<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function checkoutPayload(array $payload): array
    {
        return array_replace_recursive([
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
    }
}
