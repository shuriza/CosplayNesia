<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('fulfillment_id')->nullable()->constrained('order_fulfillments')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rental_reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 12);
            $table->string('event_type', 48);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->json('metadata')->nullable();
            $table->string('event_key', 96)->unique();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'occurred_at']);
            $table->index(['fulfillment_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_activities');
    }
};
