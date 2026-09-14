<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('quantity');
            $table->string('reason', 200)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'cancelled_at', 'start_date', 'id'], 'rental_blocks_calendar_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_blocks');
    }
};
