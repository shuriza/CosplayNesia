<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_fulfillments', function (Blueprint $table): void {
            $table->string('handoff_method', 20)->default('legacy')->after('seller_name');
            $table->text('pickup_instructions')->nullable()->after('handoff_method');
            $table->string('pickup_city', 80)->nullable()->after('pickup_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('order_fulfillments', function (Blueprint $table): void {
            $table->dropColumn(['handoff_method', 'pickup_instructions', 'pickup_city']);
        });
    }
};
