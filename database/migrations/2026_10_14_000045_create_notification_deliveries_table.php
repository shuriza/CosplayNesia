<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outbox for outbound notification channels (email today).
     *
     * The unique (channel, event_key) pair is the delivery-level analogue of
     * user_notifications.event_key: it makes "at most one message per channel per domain event"
     * a database guarantee rather than a convention, so transaction retries and queue redelivery
     * cannot send a second copy. The row is written in the same transaction as the in-app
     * notification, so a rolled-back checkout leaves no outbox row and no email.
     */
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('notification_id')->nullable()->constrained('user_notifications')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('event_key', 96);
            $table->string('type', 48);
            $table->string('category', 24);
            $table->string('status', 16)->default('pending');
            // The already-allowlisted projection, not the raw notification payload. Freezing it at
            // enqueue time means the message that is eventually sent is the one that was approved,
            // and no extra fields can leak in if the source payload grows later.
            $table->json('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider_message_id', 128)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'event_key'], 'notification_deliveries_channel_event_unique');
            $table->index(['status', 'id'], 'notification_deliveries_status_cursor_index');
            $table->index(['recipient_id', 'created_at', 'id'], 'notification_deliveries_recipient_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
