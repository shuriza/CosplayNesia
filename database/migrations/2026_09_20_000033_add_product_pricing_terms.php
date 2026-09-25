<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('sale_price')->nullable()->after('price');
            $table->unsignedBigInteger('rental_price_per_day')->nullable()->after('sale_price');
            $table->unsignedBigInteger('rental_deposit')->nullable()->after('rental_price_per_day');
            $table->unsignedSmallInteger('rental_min_days')->nullable()->after('rental_deposit');
            $table->unsignedSmallInteger('rental_max_days')->nullable()->after('rental_min_days');
        });

        DB::table('products')->where('type', 'Beli')->update(['sale_price' => DB::raw('price')]);
        DB::table('products')->where('type', 'Sewa')->update([
            'rental_price_per_day' => DB::raw('price'),
            'rental_deposit' => 0,
            'rental_min_days' => 1,
            'rental_max_days' => 30,
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'sale_price', 'rental_price_per_day', 'rental_deposit',
                'rental_min_days', 'rental_max_days',
            ]);
        });
    }
};
