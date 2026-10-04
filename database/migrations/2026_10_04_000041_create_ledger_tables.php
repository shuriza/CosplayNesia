<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('type', 20);
            $table->string('owner_type', 60)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 191)->unique();
            $table->string('description', 191)->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index('occurred_at');
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained('ledger_transactions')->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('direction', 6);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('IDR');
            $table->timestamps();

            $table->index(['ledger_account_id', 'id']);
            $table->index('ledger_transaction_id');
        });

        Schema::create('fee_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 40)->unique();
            $table->unsignedSmallInteger('platform_fee_bps')->default(0);
            $table->unsignedBigInteger('platform_fee_flat')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();
        });

        $timestamp = now();

        DB::table('ledger_accounts')->insert(array_map(fn (array $account): array => $account + [
            'owner_type' => 'platform',
            'owner_id' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], [
            ['code' => '1000', 'name' => 'Platform cash', 'type' => 'asset'],
            ['code' => '2000', 'name' => 'Seller payable', 'type' => 'liability'],
            ['code' => '2100', 'name' => 'Rental deposit held', 'type' => 'liability'],
            ['code' => '4000', 'name' => 'Platform fee revenue', 'type' => 'revenue'],
            ['code' => '4100', 'name' => 'Shipping revenue', 'type' => 'revenue'],
            ['code' => '5000', 'name' => 'Discount subsidy', 'type' => 'expense'],
            ['code' => '5100', 'name' => 'Payment gateway fee', 'type' => 'expense'],
        ]));

        DB::table('fee_policies')->insert([
            'version' => 'v1',
            'platform_fee_bps' => 0,
            'platform_fee_flat' => 0,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_policies');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
