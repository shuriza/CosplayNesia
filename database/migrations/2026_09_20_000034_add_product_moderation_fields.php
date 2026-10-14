<?php

use App\Support\ProductSearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('content_version')->default(1)->after('status');
            $table->unsignedInteger('approved_version')->nullable()->after('content_version');
            $table->timestamp('submitted_for_review_at')->nullable()->after('approved_version');
            $table->timestamp('moderated_at')->nullable()->after('submitted_for_review_at');
            $table->foreignId('moderated_by')->nullable()->after('moderated_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('moderated_by');
            $table->index(['status', 'submitted_for_review_at']);
        });

        DB::table('products')
            ->where('status', 'published')
            ->update(['approved_version' => 1]);

        ProductSearchIndex::restore();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['status', 'submitted_for_review_at']);
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn([
                'content_version',
                'approved_version',
                'submitted_for_review_at',
                'moderated_at',
                'rejection_reason',
            ]);
        });

        ProductSearchIndex::restore();
    }
};
