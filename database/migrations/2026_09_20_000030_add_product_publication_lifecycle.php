<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('status', 20)->default('draft')->after('stock');
            $table->timestamp('published_at')->nullable()->after('status');
            $table->timestamp('archived_at')->nullable()->after('published_at');
        });

        DB::table('products')->where('is_active', true)->update([
            'status' => 'published',
            'published_at' => DB::raw('created_at'),
        ]);
        DB::table('products')->where('is_active', false)->update(['status' => 'paused']);

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_active_popular_cursor_index');
            $table->dropIndex('products_active_created_cursor_index');
            $table->dropIndex('products_active_price_cursor_index');
            $table->dropIndex('products_store_catalog_index');
            $table->dropColumn('is_active');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index(['status', 'popular', 'id'], 'products_status_popular_cursor_index');
            $table->index(['status', 'created_at', 'id'], 'products_status_created_cursor_index');
            $table->index(['status', 'price', 'id'], 'products_status_price_cursor_index');
            $table->index(['store_id', 'status', 'created_at'], 'products_store_catalog_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_active')->default(false);
        });

        DB::table('products')->where('status', 'published')->update(['is_active' => true]);

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_status_popular_cursor_index');
            $table->dropIndex('products_status_created_cursor_index');
            $table->dropIndex('products_status_price_cursor_index');
            $table->dropIndex('products_store_catalog_index');
            $table->dropColumn(['status', 'published_at', 'archived_at']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index(['is_active', 'popular', 'id'], 'products_active_popular_cursor_index');
            $table->index(['is_active', 'created_at', 'id'], 'products_active_created_cursor_index');
            $table->index(['is_active', 'price', 'id'], 'products_active_price_cursor_index');
            $table->index(['store_id', 'is_active', 'created_at'], 'products_store_catalog_index');
        });
    }
};
