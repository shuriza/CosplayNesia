<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40);
            $table->string('recipient_name', 80);
            $table->string('recipient_phone', 24);
            $table->string('address_line1', 255);
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 80);
            $table->string('province', 80);
            $table->string('postal_code', 5);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_addresses');
    }
};
