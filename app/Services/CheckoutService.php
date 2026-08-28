<?php

namespace App\Services;

use App\Exceptions\FulfillmentTransitionNotAllowedException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OwnedProductCheckoutException;
use App\Exceptions\RentalCancellationNotAllowedException;
use App\Exceptions\RentalUnavailableException;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderFulfillment;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RentalReservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function create(User $user, array $items, ?string $idempotencyKey = null, ?array $handoff = null): Order
    {
        $handoffSnapshot = $this->handoffSnapshot($handoff);
        $idempotencyHash = $idempotencyKey === null ? null : $this->hashItems($items, $handoffSnapshot);

        return DB::transaction(function () use ($user, $items, $idempotencyKey, $idempotencyHash, $handoffSnapshot): Order {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($idempotencyKey !== null) {
                $existing = $lockedUser->orders()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ($existing->idempotency_hash !== $idempotencyHash) {
                        throw new IdempotencyConflictException;
                    }

                    return $this->loadOrder($existing);
                }
            }

            $productIds = collect($items)->pluck('id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();
            $products = Product::query()
                ->with('owner')
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->contains(fn (Product $product): bool => $product->seller_id === $lockedUser->id)) {
                throw new OwnedProductCheckoutException;
            }

            $snapshots = [];
            $total = 0;

            foreach ($items as $item) {
                $product = $products->get((int) $item['id']);
                if (! $product || ! $product->is_active) {
                    throw new InsufficientStockException($product?->name ?? 'Produk');
                }

                $quantity = (int) $item['quantity'];
                $snapshot = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_type' => $product->type,
                    'unit_price' => $product->price,
                    'quantity' => $quantity,
                    'rental_start_date' => null,
                    'rental_end_date' => null,
                    'seller_id' => $product->seller_id,
                    'seller_name' => $product->seller ?: $product->owner?->name,
                ];

                if ($product->type === Product::TYPE_RENTAL) {
                    $start = $this->date($item['start_date'] ?? null);
                    $end = $this->date($item['end_date'] ?? null);
                    if (! $start || ! $end) {
                        throw new RentalUnavailableException($product->name);
                    }

                    $reserved = RentalReservation::query()
                        ->where('product_id', $product->id)
                        ->where('status', RentalReservation::STATUS_RESERVED)
                        ->whereDate('start_date', '<=', $end->toDateString())
                        ->whereDate('end_date', '>=', $start->toDateString())
                        ->sum('quantity');
                    if ((int) $reserved + $quantity > (int) $product->stock) {
                        throw new RentalUnavailableException($product->name);
                    }
                    $snapshot['rental_start_date'] = $start->toDateString();
                    $snapshot['rental_end_date'] = $end->toDateString();
                } else {
                    $updated = Product::query()
                        ->whereKey($product->id)
                        ->where('is_active', true)
                        ->where('stock', '>=', $quantity)
                        ->decrement('stock', $quantity);
                    if ($updated !== 1) {
                        throw new InsufficientStockException($product->name);
                    }
                }

                $snapshots[] = $snapshot;
                $total += (int) $product->price * $quantity;
            }

            $order = $lockedUser->orders()->create([
                'total_amount' => $total,
                'status' => Order::STATUS_DEMO_CONFIRMED,
                'idempotency_key' => $idempotencyKey,
                'idempotency_hash' => $idempotencyHash,
                ...$handoffSnapshot,
            ]);

            $fulfillments = [];
            $lines = [];
            foreach ($snapshots as $snapshot) {
                if ($snapshot['seller_id'] === null) {
                    continue;
                }
                $sellerKey = (string) $snapshot['seller_id'];
                $fulfillments[$sellerKey] ??= $order->fulfillments()->create([
                    'seller_id' => $snapshot['seller_id'],
                    'seller_name' => $snapshot['seller_name'],
                    'status' => OrderFulfillment::STATUS_RECEIVED,
                    'status_changed_at' => now(),
                ]);
            }

            foreach ($snapshots as $snapshot) {
                $fulfillment = $snapshot['seller_id'] === null
                    ? null
                    : $fulfillments[(string) $snapshot['seller_id']];
                $item = $order->items()->create([
                    'fulfillment_id' => $fulfillment?->id,
                    'product_id' => $snapshot['product_id'],
                    'product_name' => $snapshot['product_name'],
                    'product_type' => $snapshot['product_type'],
                    'unit_price' => $snapshot['unit_price'],
                    'quantity' => $snapshot['quantity'],
                    'rental_start_date' => $snapshot['rental_start_date'],
                    'rental_end_date' => $snapshot['rental_end_date'],
                ]);

                $reservation = null;
                if ($snapshot['product_type'] === Product::TYPE_RENTAL) {
                    $reservation = RentalReservation::query()->create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'product_id' => $snapshot['product_id'],
                        'quantity' => $snapshot['quantity'],
                        'start_date' => $snapshot['rental_start_date'],
                        'end_date' => $snapshot['rental_end_date'],
                        'status' => RentalReservation::STATUS_RESERVED,
                    ]);
                }

                $lines[] = [
                    'snapshot' => $snapshot,
                    'item_id' => $item->id,
                    'fulfillment_id' => $fulfillment?->id,
                    'reservation_id' => $reservation?->id,
                ];
            }

            $order->load('fulfillments');
            $order->syncAggregateStatus();
            $this->recordCheckoutActivities($order, $lines, $fulfillments);

            $created = $this->loadOrder($order->fresh());
            $created->wasRecentlyCreated = true;

            return $created;
        }, 3);
    }

    public function transition(User $user, OrderFulfillment $fulfillment, string $target): OrderFulfillment
    {
        return DB::transaction(function () use ($user, $fulfillment, $target): OrderFulfillment {
            $order = Order::query()->lockForUpdate()->findOrFail($fulfillment->order_id);
            $lockedFulfillment = OrderFulfillment::query()
                ->whereKey($fulfillment->id)
                ->where('order_id', $order->id)
                ->where('seller_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();
            $items = $lockedFulfillment->items()->orderBy('id')->lockForUpdate()->get();
            $products = $this->lockedProducts($items);
            $reservations = $this->lockedReservations($items);
            $items->each(fn (OrderItem $item) => $item->setRelation(
                'rentalReservation',
                $reservations->get($item->id),
            ));
            $lockedFulfillment->setRelation('items', $items);

            if ($target === $lockedFulfillment->status) {
                return $lockedFulfillment->fresh(['items.rentalReservation', 'order.user']);
            }
            if (! in_array($target, OrderFulfillment::statuses(), true)) {
                throw new FulfillmentTransitionNotAllowedException;
            }
            $today = Carbon::today(config('app.timezone'));
            if ($target === OrderFulfillment::STATUS_COMPLETED && ! $lockedFulfillment->canComplete($today)) {
                throw new FulfillmentTransitionNotAllowedException('Sewa belum berakhir.');
            }
            if (! $lockedFulfillment->canTransitionTo($target, $today)) {
                throw new FulfillmentTransitionNotAllowedException;
            }

            $timestamp = now();
            $fromStatus = $lockedFulfillment->status;
            if ($target === OrderFulfillment::STATUS_COMPLETED) {
                foreach ($items as $item) {
                    $reservation = $reservations->get($item->id);
                    if ($reservation?->status === RentalReservation::STATUS_RESERVED) {
                        $reservation->update(['status' => RentalReservation::STATUS_COMPLETED]);
                        $this->recordActivity([
                            'order_id' => $order->id,
                            'fulfillment_id' => $lockedFulfillment->id,
                            'order_item_id' => $item->id,
                            'rental_reservation_id' => $reservation->id,
                            'actor_id' => $user->id,
                            'actor_role' => OrderActivity::ROLE_SELLER,
                            'event_type' => 'rental.completed',
                            'from_status' => RentalReservation::STATUS_RESERVED,
                            'to_status' => RentalReservation::STATUS_COMPLETED,
                            'metadata' => $this->safeLineMetadata($item),
                            'event_key' => "rental:{$reservation->id}:completed",
                            'occurred_at' => $timestamp,
                            'created_at' => $timestamp,
                        ]);
                    }
                }
            }

            if ($target === OrderFulfillment::STATUS_CANCELLED) {
                $this->cancelItems($order, $lockedFulfillment, $items, $products, $reservations, $user->id, OrderActivity::ROLE_SELLER, $timestamp);
            }

            $attributes = ['status' => $target, 'status_changed_at' => $timestamp];
            if ($target === OrderFulfillment::STATUS_ACCEPTED) {
                $attributes['accepted_at'] = $timestamp;
            } elseif ($target === OrderFulfillment::STATUS_READY) {
                $attributes['ready_at'] = $timestamp;
            } elseif ($target === OrderFulfillment::STATUS_COMPLETED) {
                $attributes['completed_at'] = $timestamp;
            } elseif ($target === OrderFulfillment::STATUS_CANCELLED) {
                $attributes['cancelled_at'] = $timestamp;
            }
            $lockedFulfillment->update($attributes);
            $this->recordActivity([
                'order_id' => $order->id,
                'fulfillment_id' => $lockedFulfillment->id,
                'actor_id' => $user->id,
                'actor_role' => OrderActivity::ROLE_SELLER,
                'event_type' => "fulfillment.{$target}",
                'from_status' => $fromStatus,
                'to_status' => $target,
                'metadata' => $this->fulfillmentMetadata($items),
                'event_key' => "fulfillment:{$lockedFulfillment->id}:{$target}",
                'occurred_at' => $timestamp,
                'created_at' => $timestamp,
            ]);

            $order->load('fulfillments');
            $order->syncAggregateStatus();

            return $lockedFulfillment->fresh(['items.rentalReservation', 'order.user']);
        }, 3);
    }

    public function cancelRental(User $user, Order $order, OrderItem $item): RentalReservation
    {
        return DB::transaction(function () use ($user, $order, $item): RentalReservation {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();
            $requestedItem = $lockedOrder->items()->whereKey($item->id)->firstOrFail();
            $fulfillment = $requestedItem->fulfillment_id === null
                ? null
                : OrderFulfillment::query()
                    ->whereKey($requestedItem->fulfillment_id)
                    ->where('order_id', $lockedOrder->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            $lockedItems = $fulfillment
                ? $fulfillment->items()->orderBy('id')->lockForUpdate()->get()
                : $lockedOrder->items()->orderBy('id')->lockForUpdate()->get();
            $lockedItem = $lockedItems->firstWhere('id', $item->id);
            if (! $lockedItem) {
                abort(404);
            }
            $reservations = $this->lockedReservations($lockedItems);
            $reservation = $reservations->get($lockedItem->id);
            if (! $reservation) {
                abort(404);
            }
            if ($reservation->status !== RentalReservation::STATUS_RESERVED
                || $reservation->start_date->lte(Carbon::today(config('app.timezone')))) {
                throw new RentalCancellationNotAllowedException;
            }

            $timestamp = now();
            $reservation->update(['status' => RentalReservation::STATUS_CANCELLED]);
            $this->recordActivity([
                'order_id' => $lockedOrder->id,
                'fulfillment_id' => $fulfillment?->id,
                'order_item_id' => $lockedItem->id,
                'rental_reservation_id' => $reservation->id,
                'actor_id' => $user->id,
                'actor_role' => OrderActivity::ROLE_BUYER,
                'event_type' => 'rental.cancelled',
                'from_status' => RentalReservation::STATUS_RESERVED,
                'to_status' => RentalReservation::STATUS_CANCELLED,
                'metadata' => $this->safeLineMetadata($lockedItem),
                'event_key' => "rental:{$reservation->id}:cancelled",
                'occurred_at' => $timestamp,
                'created_at' => $timestamp,
            ]);

            if ($fulfillment && ! $fulfillment->isTerminal()) {
                $remaining = $lockedItems->contains(fn (OrderItem $line): bool => $line->product_type !== Product::TYPE_RENTAL
                    || $reservations->get($line->id)?->status !== RentalReservation::STATUS_CANCELLED);
                if (! $remaining) {
                    $fromStatus = $fulfillment->status;
                    $fulfillment->update([
                        'status' => OrderFulfillment::STATUS_CANCELLED,
                        'status_changed_at' => $timestamp,
                        'cancelled_at' => $timestamp,
                    ]);
                    $this->recordActivity([
                        'order_id' => $lockedOrder->id,
                        'fulfillment_id' => $fulfillment->id,
                        'actor_id' => $user->id,
                        'actor_role' => OrderActivity::ROLE_BUYER,
                        'event_type' => 'fulfillment.cancelled',
                        'from_status' => $fromStatus,
                        'to_status' => OrderFulfillment::STATUS_CANCELLED,
                        'metadata' => $this->fulfillmentMetadata($lockedItems),
                        'event_key' => "fulfillment:{$fulfillment->id}:cancelled",
                        'occurred_at' => $timestamp,
                        'created_at' => $timestamp,
                    ]);
                }
            }
            $lockedOrder->load('fulfillments');
            $lockedOrder->syncAggregateStatus();

            return $reservation->fresh();
        }, 3);
    }

    private function cancelItems(Order $order, ?OrderFulfillment $fulfillment, $items, $products, $reservations, int $actorId, string $actorRole, Carbon $timestamp): void
    {
        foreach ($items as $item) {
            $reservation = $reservations->get($item->id);
            if ($reservation?->status === RentalReservation::STATUS_RESERVED) {
                $reservation->update(['status' => RentalReservation::STATUS_CANCELLED]);
                $this->recordActivity([
                    'order_id' => $order->id,
                    'fulfillment_id' => $fulfillment?->id,
                    'order_item_id' => $item->id,
                    'rental_reservation_id' => $reservation->id,
                    'actor_id' => $actorId,
                    'actor_role' => $actorRole,
                    'event_type' => 'rental.cancelled',
                    'from_status' => RentalReservation::STATUS_RESERVED,
                    'to_status' => RentalReservation::STATUS_CANCELLED,
                    'metadata' => $this->safeLineMetadata($item),
                    'event_key' => "rental:{$reservation->id}:cancelled",
                    'occurred_at' => $timestamp,
                    'created_at' => $timestamp,
                ]);
            }
            if ($item->product_type !== Product::TYPE_SALE || $item->stock_released_at !== null) {
                continue;
            }
            $product = $item->product_id === null ? null : $products->get($item->product_id);
            if ($product === null) {
                continue;
            }
            $product->increment('stock', $item->quantity);
            $item->update(['stock_released_at' => now()]);
        }
    }

    private function recordCheckoutActivities(Order $order, array $lines, array $fulfillments): void
    {
        $timestamp = now();
        $rentalCount = 0;
        foreach ($lines as $line) {
            if ($line['snapshot']['product_type'] === Product::TYPE_RENTAL) {
                $rentalCount++;
            }
        }

        $this->recordActivity([
            'order_id' => $order->id,
            'actor_id' => $order->user_id,
            'actor_role' => OrderActivity::ROLE_BUYER,
            'event_type' => 'checkout.created',
            'to_status' => $order->status,
            'metadata' => [
                'item_count' => count($lines),
                'fulfillment_count' => count($fulfillments),
                'rental_count' => $rentalCount,
                'total_amount' => $order->total_amount,
            ],
            'event_key' => "order:{$order->id}:checkout.created",
            'occurred_at' => $timestamp,
            'created_at' => $timestamp,
        ]);

        $grouped = [];
        foreach ($lines as $line) {
            $snapshot = $line['snapshot'];
            if ($snapshot['seller_id'] === null) {
                continue;
            }
            $grouped[$line['fulfillment_id']][] = $line;
        }

        foreach ($fulfillments as $fulfillment) {
            $group = $grouped[$fulfillment->id] ?? [];
            $this->recordActivity([
                'order_id' => $order->id,
                'fulfillment_id' => $fulfillment->id,
                'actor_id' => $order->user_id,
                'actor_role' => OrderActivity::ROLE_BUYER,
                'event_type' => 'fulfillment.received',
                'to_status' => OrderFulfillment::STATUS_RECEIVED,
                'metadata' => $this->fulfillmentGroupMetadata($group),
                'event_key' => "fulfillment:{$fulfillment->id}:received",
                'occurred_at' => $timestamp,
                'created_at' => $timestamp,
            ]);
        }

        foreach ($lines as $line) {
            $snapshot = $line['snapshot'];
            if ($snapshot['product_type'] !== Product::TYPE_RENTAL || $line['reservation_id'] === null) {
                continue;
            }

            $this->recordActivity([
                'order_id' => $order->id,
                'fulfillment_id' => $line['fulfillment_id'],
                'order_item_id' => $line['item_id'],
                'rental_reservation_id' => $line['reservation_id'],
                'actor_id' => $order->user_id,
                'actor_role' => OrderActivity::ROLE_BUYER,
                'event_type' => 'rental.reserved',
                'to_status' => RentalReservation::STATUS_RESERVED,
                'metadata' => $this->safeLineMetadataFromSnapshot($snapshot),
                'event_key' => "rental:{$line['reservation_id']}:reserved",
                'occurred_at' => $timestamp,
                'created_at' => $timestamp,
            ]);
        }
    }

    private function recordActivity(array $attributes): void
    {
        if (array_key_exists('metadata', $attributes) && is_array($attributes['metadata'])) {
            $attributes['metadata'] = json_encode($attributes['metadata'], JSON_THROW_ON_ERROR);
        }

        OrderActivity::query()->insertOrIgnore([$attributes]);
    }

    private function fulfillmentMetadata($items): array
    {
        return [
            'item_count' => $items->count(),
            'sale_count' => $items->where('product_type', Product::TYPE_SALE)->count(),
            'rental_count' => $items->where('product_type', Product::TYPE_RENTAL)->count(),
        ];
    }

    private function fulfillmentGroupMetadata(array $group): array
    {
        $saleCount = 0;
        $rentalCount = 0;
        foreach ($group as $line) {
            $type = $line['snapshot']['product_type'] ?? null;
            if ($type === Product::TYPE_SALE) {
                $saleCount++;
            } elseif ($type === Product::TYPE_RENTAL) {
                $rentalCount++;
            }
        }

        return [
            'item_count' => count($group),
            'sale_count' => $saleCount,
            'rental_count' => $rentalCount,
        ];
    }

    private function safeLineMetadata($item): array
    {
        return [
            'product_id' => (int) $item->product_id,
            'product_name' => $item->product_name,
            'product_type' => $item->product_type,
            'quantity' => (int) $item->quantity,
            'rental_start_date' => $item->rental_start_date?->toDateString(),
            'rental_end_date' => $item->rental_end_date?->toDateString(),
        ];
    }

    private function safeLineMetadataFromSnapshot(array $snapshot): array
    {
        return [
            'product_id' => (int) $snapshot['product_id'],
            'product_name' => $snapshot['product_name'],
            'product_type' => $snapshot['product_type'],
            'quantity' => (int) $snapshot['quantity'],
            'rental_start_date' => $snapshot['rental_start_date'],
            'rental_end_date' => $snapshot['rental_end_date'],
        ];
    }

    private function lockedProducts($items)
    {
        $ids = $items->pluck('product_id')->filter()->unique()->sort()->values();

        return Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function lockedReservations($items)
    {
        $ids = $items->pluck('id')->sort()->values();

        return RentalReservation::query()->whereIn('order_item_id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('order_item_id');
    }

    private function loadOrder(Order $order): Order
    {
        return $order->load('items.rentalReservation', 'items.fulfillment', 'fulfillments.items.rentalReservation');
    }

    private function hashItems(array $items, array $snapshot): string
    {
        $canonical = array_map(static function (array $item): array {
            return [
                'id' => (int) $item['id'],
                'quantity' => (int) $item['quantity'],
                'start_date' => $item['start_date'] ?? null,
                'end_date' => $item['end_date'] ?? null,
            ];
        }, $items);
        usort($canonical, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return hash('sha256', json_encode([
            'items' => $canonical,
            'handoff' => $snapshot,
        ], JSON_THROW_ON_ERROR));
    }

    private function handoffSnapshot(?array $handoff): array
    {
        $handoff ??= [];

        return [
            'recipient_name' => trim((string) ($handoff['recipient_name'] ?? '')) ?: null,
            'recipient_phone' => trim((string) ($handoff['recipient_phone'] ?? '')) ?: null,
            'recipient_email' => mb_strtolower(trim((string) ($handoff['recipient_email'] ?? ''))) ?: null,
            'address_line1' => trim((string) ($handoff['address_line1'] ?? '')) ?: null,
            'address_line2' => trim((string) ($handoff['address_line2'] ?? '')) ?: null,
            'city' => trim((string) ($handoff['city'] ?? '')) ?: null,
            'province' => trim((string) ($handoff['province'] ?? '')) ?: null,
            'postal_code' => trim((string) ($handoff['postal_code'] ?? '')) ?: null,
            'handoff_note' => trim((string) ($handoff['handoff_note'] ?? '')) ?: null,
        ];
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
