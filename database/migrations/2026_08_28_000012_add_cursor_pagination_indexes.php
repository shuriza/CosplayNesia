<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['seller_id', 'created_at', 'id'], 'products_seller_cursor_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at', 'id'], 'orders_user_cursor_index');
        });

        Schema::table('order_fulfillments', function (Blueprint $table): void {
            $table->dropIndex(['seller_id', 'status', 'created_at']);
            $table->index(['seller_id', 'created_at', 'id'], 'fulfillments_seller_cursor_index');
            $table->index(['seller_id', 'status', 'created_at', 'id'], 'fulfillments_seller_status_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_fulfillments', function (Blueprint $table): void {
            $table->dropIndex('fulfillments_seller_status_cursor_index');
            $table->dropIndex('fulfillments_seller_cursor_index');
            $table->index(['seller_id', 'status', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_user_cursor_index');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_seller_cursor_index');
        });
    }
};
