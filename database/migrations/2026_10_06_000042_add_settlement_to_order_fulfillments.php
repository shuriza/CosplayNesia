<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_fulfillments', function (Blueprint $table): void {
            // Settlement state of the money owed to the seller for this fulfillment. This is a
            // domain classification, not a mutable balance: the authoritative balance is always
            // the seller_payable ledger account (see docs/adr/0002-seller-balance-projection.md).
            $table->string('settlement_status', 20)->default('pending')->after('status');
            $table->timestamp('settled_at')->nullable()->after('settlement_status');

            $table->index(['seller_id', 'settlement_status'], 'fulfillments_seller_settlement_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_fulfillments', function (Blueprint $table): void {
            $table->dropIndex('fulfillments_seller_settlement_index');
            $table->dropColumn(['settlement_status', 'settled_at']);
        });
    }
};
