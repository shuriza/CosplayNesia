<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table): void {
            // A correction is a new transaction, never an edit. This column is the only link back
            // to what it corrects; it is unique so one original cannot be reversed twice.
            $table->foreignId('reverses_transaction_id')->nullable()->unique()->after('reference_id')
                ->constrained('ledger_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reverses_transaction_id');
        });
    }
};
