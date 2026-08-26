<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('recipient_name', 80)->nullable();
            $table->string('recipient_phone', 24)->nullable();
            $table->string('recipient_email', 255)->nullable();
            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('province', 80)->nullable();
            $table->string('postal_code', 5)->nullable();
            $table->text('handoff_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'recipient_name', 'recipient_phone', 'recipient_email', 'address_line1', 'address_line2',
                'city', 'province', 'postal_code', 'handoff_note',
            ]);
        });
    }
};
