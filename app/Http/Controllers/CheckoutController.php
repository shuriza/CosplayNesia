<?php

namespace App\Http\Controllers;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OwnedProductCheckoutException;
use App\Exceptions\RentalUnavailableException;
use App\Http\Requests\CheckoutRequest;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function store(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        $validated = $request->validated();
        $recipient = $validated['recipient'];
        $address = $validated['address'];

        try {
            $order = $checkout->create(
                $request->user(),
                $validated['items'],
                $validated['idempotency_key'] ?? null,
                [
                    'recipient_name' => $recipient['name'],
                    'recipient_phone' => $recipient['phone'],
                    'recipient_email' => $recipient['email'],
                    'address_line1' => $address['line1'],
                    'address_line2' => $address['line2'] ?? null,
                    'city' => $address['city'],
                    'province' => $address['province'],
                    'postal_code' => $address['postal_code'],
                    'handoff_note' => $validated['handoff_note'] ?? null,
                ],
            );
        } catch (InsufficientStockException|OwnedProductCheckoutException|RentalUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Checkout demo berhasil.',
            'order_id' => $order->id,
            'order' => $order,
        ], $order->wasRecentlyCreated ? 201 : 200);
    }
}
