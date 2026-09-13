<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->text('body')->nullable()->after('rating');
        });

        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'created_at']);
            $table->index(['product_id', 'created_at', 'id'], 'reviews_product_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->dropIndex('reviews_product_cursor_index');
            $table->index(['product_id', 'created_at']);
        });

        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->dropColumn('body');
        });
    }
};
