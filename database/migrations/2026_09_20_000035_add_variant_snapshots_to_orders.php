<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            $table->string('variant_sku', 64)->nullable()->after('product_name');
            $table->string('variant_label', 100)->nullable()->after('variant_sku');
            $table->string('variant_size', 40)->nullable()->after('variant_label');
            $table->string('variant_color', 50)->nullable()->after('variant_size');
            $table->string('variant_condition', 20)->nullable()->after('variant_color');
        });
        Schema::table('rental_reservations', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            $table->index(['product_variant_id', 'status', 'start_date', 'end_date'], 'rental_variant_capacity_index');
        });

        DB::table('order_items')->whereNotNull('product_id')->orderBy('id')->chunkById(200, function ($items): void {
            foreach ($items as $item) {
                $variant = DB::table('product_variants')
                    ->where('product_id', $item->product_id)
                    ->orderByDesc('is_default')
                    ->orderBy('position')
                    ->orderBy('id')
                    ->first();
                if (! $variant) {
                    continue;
                }
                DB::table('order_items')->where('id', $item->id)->update([
                    'product_variant_id' => $variant->id,
                    'variant_sku' => $variant->sku,
                    'variant_label' => $variant->label,
                    'variant_size' => $variant->size,
                    'variant_color' => $variant->color,
                    'variant_condition' => $variant->condition,
                ]);
                DB::table('rental_reservations')->where('order_item_id', $item->id)->update([
                    'product_variant_id' => $variant->id,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_reservations', function (Blueprint $table): void {
            $table->dropIndex('rental_variant_capacity_index');
            $table->dropConstrainedForeignId('product_variant_id');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn([
                'variant_sku',
                'variant_label',
                'variant_size',
                'variant_color',
                'variant_condition',
            ]);
        });
    }
};
