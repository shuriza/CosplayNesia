<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()->orders()
            ->with('items')
            ->latest()
            ->get()
            ->each(function ($order): void {
                $order->items->each(function ($item): void {
                    $item->setAttribute('name', $item->product_name);
                    $item->setAttribute('price', $item->unit_price);
                });
            });

        return response()->json($orders);
    }
}
