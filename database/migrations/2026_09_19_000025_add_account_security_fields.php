<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pending_email')->nullable()->unique()->after('email');
            $table->string('pending_email_token_hash', 64)->nullable()->after('pending_email');
            $table->timestamp('pending_email_requested_at')->nullable()->after('pending_email_token_hash');
            $table->string('terms_version', 32)->nullable()->after('email_verified_at');
            $table->string('privacy_version', 32)->nullable()->after('terms_version');
            $table->string('rental_policy_version', 32)->nullable()->after('privacy_version');
            $table->timestamp('legal_accepted_at')->nullable()->after('rental_policy_version');
            $table->timestamp('deactivated_at')->nullable()->index()->after('legal_accepted_at');
            $table->timestamp('anonymized_at')->nullable()->after('deactivated_at');
        });

        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'created_at', 'id'], 'security_events_user_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'pending_email', 'pending_email_token_hash', 'pending_email_requested_at',
                'terms_version', 'privacy_version', 'rental_policy_version',
                'legal_accepted_at', 'deactivated_at',
                'anonymized_at',
            ]);
        });
    }
};
