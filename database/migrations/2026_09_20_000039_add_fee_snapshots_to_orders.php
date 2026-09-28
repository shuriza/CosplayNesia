<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('merchandise_subtotal')->default(0)->after('total_amount');
            $table->unsignedBigInteger('shipping_fee')->nullable()->after('merchandise_subtotal');
            $table->unsignedBigInteger('platform_fee')->default(0)->after('shipping_fee');
            $table->unsignedBigInteger('rental_deposit')->default(0)->after('platform_fee');
            $table->unsignedBigInteger('grand_total')->nullable()->after('rental_deposit');
            $table->string('fee_currency', 3)->default('IDR')->after('grand_total');
        });

        DB::table('orders')->update([
            'merchandise_subtotal' => DB::raw('total_amount'),
            'platform_fee' => 0,
            'rental_deposit' => 0,
            'fee_currency' => 'IDR',
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'merchandise_subtotal', 'shipping_fee', 'platform_fee',
                'rental_deposit', 'grand_total', 'fee_currency',
            ]);
        });
    }
};
