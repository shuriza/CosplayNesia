<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('origin_address_line1', 255)->nullable()->after('city');
            $table->string('origin_address_line2', 255)->nullable()->after('origin_address_line1');
            $table->string('origin_province', 80)->nullable()->after('origin_address_line2');
            $table->string('origin_postal_code', 5)->nullable()->after('origin_province');
            $table->boolean('pickup_enabled')->default(false)->after('origin_postal_code');
            $table->string('pickup_instructions', 500)->nullable()->after('pickup_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn([
                'origin_address_line1', 'origin_address_line2', 'origin_province',
                'origin_postal_code', 'pickup_enabled', 'pickup_instructions',
            ]);
        });
    }
};
