<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->foreignId('seller_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->text('seller_reply')->nullable()->after('body');
            $table->timestamp('seller_replied_at')->nullable()->after('seller_reply');
        });

        // Snapshot the current owner so a seller keeps inbox access after the listing is deleted.
        DB::statement(<<<'SQL'
            UPDATE product_reviews
            SET seller_id = (SELECT products.seller_id FROM products WHERE products.id = product_reviews.product_id)
            WHERE product_id IS NOT NULL
            SQL);

        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->index(['seller_id', 'created_at', 'id'], 'reviews_seller_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->dropIndex('reviews_seller_cursor_index');
        });

        Schema::table('product_reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('seller_id');
            $table->dropColumn(['seller_reply', 'seller_replied_at']);
        });
    }
};
