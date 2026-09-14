<?php

namespace App\Http\Controllers;

use App\Http\Requests\RentalBlockRequest;
use App\Models\Product;
use App\Models\RentalBlock;
use App\Services\RentalCapacity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentalBlockController extends Controller
{
    public function index(RentalBlockRequest $request, Product $product, RentalCapacity $capacity): JsonResponse
    {
        abort_unless($product->type === Product::TYPE_RENTAL, 404);
        $input = $request->validated();
        $perPage = (int) ($input['per_page'] ?? 5);
        $blocks = RentalBlock::query()
            ->where('product_id', $product->id)
            ->whereNull('cancelled_at')
            ->where('start_date', '<=', $input['end_date'])
            ->where('end_date', '>=', $input['start_date'])
            ->orderBy('start_date')->orderBy('id')
            ->cursorPaginate($perPage);

        return response()->json([
            'product' => $product->only(['id', 'name', 'stock']),
            'days' => $capacity->summary($product, $input['start_date'], $input['end_date'])['days'],
            'data' => $blocks->getCollection()->map(fn (RentalBlock $block): array => $this->payload($block)),
            'pagination' => [
                'next_cursor' => $blocks->nextCursor()?->encode(),
                'has_more' => $blocks->hasMorePages(),
                'per_page' => $perPage,
            ],
        ]);
    }

    public function store(RentalBlockRequest $request, Product $product, RentalCapacity $capacity): JsonResponse
    {
        $input = $request->validated();
        $block = DB::transaction(function () use ($request, $product, $capacity, $input): RentalBlock {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            abort_unless($locked->seller_id === $request->user()->id, 403);
            abort_unless($locked->type === Product::TYPE_RENTAL, 404);
            $allocated = $capacity->peak($locked, $input['start_date'], $input['end_date']);
            abort_if($allocated + (int) $input['quantity'] > $locked->stock, 409,
                'Kapasitas tidak cukup. Blok tidak boleh bertabrakan dengan reservasi atau blok sewa lain.');

            return RentalBlock::query()->create([
                'product_id' => $locked->id,
                'start_date' => $input['start_date'],
                'end_date' => $input['end_date'],
                'quantity' => (int) $input['quantity'],
                'reason' => filled($input['reason'] ?? null) ? trim($input['reason']) : null,
            ]);
        }, 3);

        return response()->json($this->payload($block), 201);
    }

    public function destroy(Request $request, Product $product, RentalBlock $block): JsonResponse
    {
        DB::transaction(function () use ($request, $product, $block): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            abort_unless($locked->seller_id === $request->user()->id, 403);
            abort_unless($locked->type === Product::TYPE_RENTAL, 404);
            $lockedBlock = RentalBlock::query()->where('product_id', $locked->id)
                ->lockForUpdate()->findOrFail($block->id);
            if ($lockedBlock->cancelled_at === null) {
                $lockedBlock->update(['cancelled_at' => now()]);
            }
        }, 3);

        return response()->json(null, 204);
    }

    private function payload(RentalBlock $block): array
    {
        return [
            'id' => $block->id,
            'start_date' => $block->start_date->toDateString(),
            'end_date' => $block->end_date->toDateString(),
            'quantity' => $block->quantity,
            'reason' => $block->reason,
        ];
    }
}
