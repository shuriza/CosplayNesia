<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function create(User $user, array $items): Order
    {
        return DB::transaction(function () use ($user, $items): Order {
            $snapshots = [];
            $total = 0;

            foreach ($items as $item) {
                $product = Product::query()->findOrFail($item['id']);
                $quantity = (int) $item['quantity'];

                $updated = Product::query()
                    ->whereKey($product->id)
                    ->where('is_active', true)
                    ->where('stock', '>=', $quantity)
                    ->decrement('stock', $quantity);

                if ($updated !== 1) {
                    throw new InsufficientStockException($product->name);
                }

                $snapshots[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $product->price,
                    'quantity' => $quantity,
                ];
                $total += $product->price * $quantity;
            }

            $order = $user->orders()->create([
                'total_amount' => $total,
                'status' => Order::STATUS_DEMO_CONFIRMED,
            ]);
            $order->items()->createMany($snapshots);

            return $order->load('items');
        }, 3);
    }
}
