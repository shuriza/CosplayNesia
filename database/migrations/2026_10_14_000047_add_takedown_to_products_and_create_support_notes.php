<?php

use App\Support\ProductSearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operator-driven listing removal.
     *
     * Takedown is modelled as a real product status rather than a flag beside `published`, so every
     * existing visibility path (catalog, cart, checkout, availability, review feed, storefront)
     * excludes it without a single new condition. `moderation_hold_status` remembers what the
     * listing was before the takedown so a restore is exact instead of a guess at `published`.
     *
     * The reason is stored on the row because the seller is told why their listing disappeared;
     * private operator notes live in their own table and never reach the seller.
     *
     * Adding a foreign key to `products` is not an in-place change on SQLite: the table is rebuilt,
     * and a rebuild drops the FTS synchronization triggers. The index is restored immediately after
     * the ALTER so the catalog does not silently stop matching new or edited rows.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('moderation_hold_status', 20)->nullable()->after('status');
            $table->text('takedown_reason')->nullable()->after('moderation_hold_status');
            $table->timestamp('taken_down_at')->nullable()->after('takedown_reason');
            $table->foreignId('taken_down_by')->nullable()->after('taken_down_at')->constrained('users')->nullOnDelete();

            $table->index(['status', 'id'], 'products_status_takedown_index');
        });

        ProductSearchIndex::restore();

        Schema::create('support_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_role', 20);
            // Target is a loose reference: notes must survive the deletion of what they describe.
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id');
            $table->text('body');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['target_type', 'target_id', 'occurred_at'], 'support_notes_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_notes');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_status_takedown_index');
            $table->dropConstrainedForeignId('taken_down_by');
            $table->dropColumn(['moderation_hold_status', 'takedown_reason', 'taken_down_at']);
        });

        // Dropping the foreign key rebuilds the table again, so the triggers need restoring here too.
        ProductSearchIndex::restore();
    }
};
