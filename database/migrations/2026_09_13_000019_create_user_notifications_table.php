<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately not named "notifications": the User model uses Laravel's Notifiable trait,
     * whose notifications() relation expects the framework's morph/uuid schema. A separate
     * table keeps this purpose-built feed from shadowing it.
     */
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fulfillment_id')->nullable()->constrained('order_fulfillments')->nullOnDelete();
            $table->foreignId('product_review_id')->nullable()->constrained('product_reviews')->nullOnDelete();
            $table->string('type', 48);
            $table->json('payload')->nullable();
            // Mirrors order_activities: a unique key makes every fan-out insert idempotent
            // under transaction retries and same-state mutation replays.
            $table->string('event_key', 96)->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['recipient_id', 'created_at', 'id'], 'notifications_recipient_cursor_index');
            $table->index(['recipient_id', 'read_at'], 'notifications_recipient_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
