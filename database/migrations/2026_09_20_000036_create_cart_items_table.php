<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->date('rental_start_date')->nullable();
            $table->date('rental_end_date')->nullable();
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'product_variant_id']);
            $table->index(['user_id', 'updated_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
