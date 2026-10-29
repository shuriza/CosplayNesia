<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->boolean('payout_frozen')->default(false)->after('pickup_instructions');
            $table->text('payout_freeze_reason')->nullable()->after('payout_frozen');
            $table->timestamp('payout_frozen_at')->nullable()->after('payout_freeze_reason');
            $table->foreignId('payout_frozen_by')->nullable()->after('payout_frozen_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payout_frozen_by');
            $table->dropColumn(['payout_frozen', 'payout_freeze_reason', 'payout_frozen_at']);
        });
    }
};
