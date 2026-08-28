<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('rating');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('rating', 2, 1)->default(5);
        });

        Schema::dropIfExists('product_reviews');
    }
};
