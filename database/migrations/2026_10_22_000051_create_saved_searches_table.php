<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A saved search stores the catalog filter the shopper already uses: same keys, same values,
     * same validation as `GET /api/products`. Availability alerts are saved searches with a date
     * window, so one table serves both and a new alert type never needs a second schema.
     *
     * `last_matched_product_id` is a per-search cursor into the popular/id ordering the catalog
     * itself uses, so a scan never re-announces listings the shopper has already been told about.
     */
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('filters');
            $table->boolean('alert_enabled')->default(true);
            $table->timestamp('last_scanned_at')->nullable();
            $table->unsignedBigInteger('last_matched_product_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['user_id', 'created_at', 'id'], 'saved_searches_user_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
