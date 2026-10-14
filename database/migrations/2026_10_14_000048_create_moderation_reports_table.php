<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reports about a listing, account, message, or review.
     *
     * `evidence` is a bounded snapshot taken when the report is filed, not a foreign key, because
     * the thing being reported is exactly what is likely to disappear next: a seller edits or
     * deletes the listing, a reply is removed, a message stays append-only but its context moves on.
     * An operator resolving a report three weeks later must still be able to see what was reported.
     * The snapshot is deliberately narrow — ids and content, never credentials or contact details.
     */
    public function up(): void
    {
        Schema::create('moderation_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_role', 20);
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->string('category', 24);
            $table->text('reason');
            $table->json('evidence')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('resolution')->nullable();
            $table->string('resolution_action', 20)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id'], 'moderation_reports_status_cursor_index');
            $table->index(['target_type', 'target_id'], 'moderation_reports_target_index');
            $table->index(['reporter_id', 'target_type', 'target_id'], 'moderation_reports_reporter_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_reports');
    }
};
