<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_active_newest_cursor_index');
            $table->index(['is_active', 'created_at', 'id'], 'products_active_created_cursor_index');
            $table->dropColumn('newest');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('newest')->default(0);
            $table->dropIndex('products_active_created_cursor_index');
            $table->index(['is_active', 'newest', 'id'], 'products_active_newest_cursor_index');
        });
    }
};
