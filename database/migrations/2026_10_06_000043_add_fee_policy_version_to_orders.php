<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Version of the fee policy that applied when the order was created. Orders must never
            // resolve fees against the current policy: a later policy change would silently change
            // the meaning of old orders. See docs/adr/0001-double-entry-ledger.md.
            $table->string('fee_policy_version', 40)->nullable()->after('fee_currency');

            $table->index('fee_policy_version');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['fee_policy_version']);
            $table->dropColumn('fee_policy_version');
        });
    }
};
