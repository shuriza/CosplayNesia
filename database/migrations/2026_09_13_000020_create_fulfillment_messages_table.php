<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fulfillment_id')->constrained('order_fulfillments')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            // Role is stored rather than derived so the thread still renders correctly after a
            // sender account is deleted, mirroring how order_activities snapshots actor_role.
            $table->string('sender_role', 12);
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['fulfillment_id', 'created_at', 'id'], 'messages_thread_cursor_index');
        });

        Schema::table('order_fulfillments', function (Blueprint $table): void {
            // Read position is a message id, not a timestamp: created_at has second precision, so
            // "created_at > marker" silently misses messages written within the same second.
            // Ids are monotonic, so "id > marker" is exact regardless of clock granularity.
            $table->foreignId('buyer_read_message_id')->nullable()->after('cancelled_at')
                ->constrained('fulfillment_messages')->nullOnDelete();
            $table->foreignId('seller_read_message_id')->nullable()->after('buyer_read_message_id')
                ->constrained('fulfillment_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_fulfillments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('buyer_read_message_id');
            $table->dropConstrainedForeignId('seller_read_message_id');
        });

        Schema::dropIfExists('fulfillment_messages');
    }
};
