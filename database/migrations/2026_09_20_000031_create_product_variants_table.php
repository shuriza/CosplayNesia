<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64);
            $table->string('label', 100);
            $table->string('size', 40)->nullable();
            $table->string('color', 50)->nullable();
            $table->string('condition', 20)->default('good');
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'sku']);
            $table->index(['product_id', 'position', 'id'], 'product_variants_order_index');
        });

        DB::table('products')->orderBy('id')->chunkById(200, function ($products): void {
            $timestamp = now();
            DB::table('product_variants')->insert($products->map(fn ($product): array => [
                'product_id' => $product->id,
                'sku' => 'LEGACY-'.$product->id,
                'label' => $product->size ?: 'Standar',
                'size' => $product->size,
                'color' => null,
                'condition' => 'good',
                'stock' => $product->stock,
                'position' => 0,
                'is_default' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
