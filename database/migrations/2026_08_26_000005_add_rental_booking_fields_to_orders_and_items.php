<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->nullable();
            $table->string('idempotency_hash', 64)->nullable();
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('product_type', 10)->nullable();
            $table->date('rental_start_date')->nullable();
            $table->date('rental_end_date')->nullable();
            $table->index(['product_type', 'rental_start_date', 'rental_end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex(['product_type', 'rental_start_date', 'rental_end_date']);
            $table->dropColumn(['product_type', 'rental_start_date', 'rental_end_date']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_user_id_idempotency_key_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_hash']);
        });
    }
};
