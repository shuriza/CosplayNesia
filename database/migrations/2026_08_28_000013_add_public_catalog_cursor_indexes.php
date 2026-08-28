<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropIndex(['popular']);
            $table->dropIndex(['newest']);
            $table->index(['is_active', 'popular', 'id'], 'products_active_popular_cursor_index');
            $table->index(['is_active', 'newest', 'id'], 'products_active_newest_cursor_index');
            $table->index(['is_active', 'price', 'id'], 'products_active_price_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_active_price_cursor_index');
            $table->dropIndex('products_active_newest_cursor_index');
            $table->dropIndex('products_active_popular_cursor_index');
            $table->index('is_active');
            $table->index('popular');
            $table->index('newest');
        });
    }
};
