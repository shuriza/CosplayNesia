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

        $this->restoreSqliteSearch();
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

        $this->restoreSqliteSearch();
    }

    private function restoreSqliteSearch(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS products_search_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS products_search_update');
        DB::unprepared('DROP TRIGGER IF EXISTS products_search_insert');
        DB::statement(<<<'SQL'
            CREATE TRIGGER products_search_insert AFTER INSERT ON products BEGIN
                INSERT INTO product_search(rowid, product_id, name, series, seller, city)
                VALUES (new.id, new.id, coalesce(new.name, ''), coalesce(new.series, ''), coalesce(new.seller, ''), coalesce(new.city, ''));
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER products_search_update AFTER UPDATE OF name, series, seller, city ON products BEGIN
                DELETE FROM product_search WHERE rowid = old.id;
                INSERT INTO product_search(rowid, product_id, name, series, seller, city)
                VALUES (new.id, new.id, coalesce(new.name, ''), coalesce(new.series, ''), coalesce(new.seller, ''), coalesce(new.city, ''));
            END
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER products_search_delete AFTER DELETE ON products BEGIN
                DELETE FROM product_search WHERE rowid = old.id;
            END
            SQL);
        DB::statement('DELETE FROM product_search');
        DB::statement(<<<'SQL'
            INSERT INTO product_search(rowid, product_id, name, series, seller, city)
            SELECT id, id, coalesce(name, ''), coalesce(series, ''), coalesce(seller, ''), coalesce(city, '')
            FROM products
            SQL);
    }
};
