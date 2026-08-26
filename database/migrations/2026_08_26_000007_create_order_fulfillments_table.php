<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('seller_name', 80)->nullable();
            $table->string('status', 24)->default('received')->index();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'seller_id']);
            $table->index(['seller_id', 'status', 'created_at']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('fulfillment_id')->nullable()->after('order_id')->constrained('order_fulfillments')->nullOnDelete();
            $table->timestamp('stock_released_at')->nullable()->after('quantity');
            $table->index(['fulfillment_id', 'product_type']);
        });

        $historical = DB::table('order_items')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('products.seller_id')
            ->select([
                'order_items.id', 'order_items.order_id', 'order_items.product_type',
                'products.seller_id', 'products.seller', 'orders.created_at',
            ])
            ->orderBy('order_items.id')
            ->get();
        $fulfillments = [];
        foreach ($historical as $item) {
            if ($item->product_type === 'Sewa'
                && DB::table('rental_reservations')->where('order_item_id', $item->id)->where('status', 'reserved')->exists()) {
                continue;
            }
            $key = $item->order_id.':'.$item->seller_id;
            if (! isset($fulfillments[$key])) {
                $timestamp = $item->created_at ?? now();
                $fulfillments[$key] = DB::table('order_fulfillments')->insertGetId([
                    'order_id' => $item->order_id,
                    'seller_id' => $item->seller_id,
                    'seller_name' => $item->seller,
                    'status' => 'completed',
                    'status_changed_at' => $timestamp,
                    'completed_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
            DB::table('order_items')->where('id', $item->id)->update(['fulfillment_id' => $fulfillments[$key]]);
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex(['fulfillment_id', 'product_type']);
            $table->dropForeign(['fulfillment_id']);
            $table->dropColumn(['fulfillment_id', 'stock_released_at']);
        });

        Schema::dropIfExists('order_fulfillments');
    }
};
