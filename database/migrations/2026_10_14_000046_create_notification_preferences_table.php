<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user, per-channel opt-out of a notification category.
     *
     * A missing row means "use the catalog default" (enabled), so a new category never silently
     * becomes disabled for existing accounts. Rows are only written when a user changes something,
     * or when a one-click unsubscribe link is followed.
     *
     * Categories that the catalog marks mandatory (account security) are never looked up here:
     * the resolver refuses to consult this table for them, so no row can switch them off.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('category', 24);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'channel', 'category'], 'notification_preferences_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
