<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Single durable baseline event per existing record. We do NOT invent
        // historical transition sequences; each pre-existing order/fulfillment/
        // reservation gets exactly one "imported" baseline row with a unique
        // event_key, so the migration is idempotent and safe to rerun.

        $now = now();

        DB::table('orders')->orderBy('id')->each(function ($order) use ($now): void {
            DB::table('order_activities')->insertOrIgnore([
                'order_id' => $order->id,
                'actor_id' => $order->user_id,
                'actor_role' => 'system',
                'event_type' => 'order.imported',
                'event_key' => "order:{$order->id}:imported",
                'occurred_at' => $order->created_at ?? $now,
                'created_at' => $now,
            ]);
        });

        DB::table('order_fulfillments')->orderBy('id')->each(function ($fulfillment) use ($now): void {
            DB::table('order_activities')->insertOrIgnore([
                'order_id' => $fulfillment->order_id,
                'fulfillment_id' => $fulfillment->id,
                'actor_id' => $fulfillment->seller_id,
                'actor_role' => 'system',
                'event_type' => 'fulfillment.imported',
                'to_status' => $fulfillment->status,
                'event_key' => "fulfillment:{$fulfillment->id}:imported",
                'occurred_at' => $fulfillment->created_at ?? $now,
                'created_at' => $now,
            ]);
        });

        DB::table('rental_reservations')->orderBy('id')->each(function ($reservation) use ($now): void {
            DB::table('order_activities')->insertOrIgnore([
                'order_id' => $reservation->order_id,
                'order_item_id' => $reservation->order_item_id,
                'rental_reservation_id' => $reservation->id,
                'actor_id' => null,
                'actor_role' => 'system',
                'event_type' => 'rental.imported',
                'to_status' => $reservation->status,
                'event_key' => "rental:{$reservation->id}:imported",
                'occurred_at' => $reservation->created_at ?? $now,
                'created_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('order_activities')
            ->where('event_key', 'like', '%:imported')
            ->delete();
    }
};
