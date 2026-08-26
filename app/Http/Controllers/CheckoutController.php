<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Http\Requests\CheckoutRequest;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function store(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        try {
            $order = $checkout->create($request->user(), $request->validated('items'));
        } catch (InsufficientStockException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Checkout demo berhasil.',
            'order_id' => $order->id,
            'order' => $order,
        ], 201);
    }
}
