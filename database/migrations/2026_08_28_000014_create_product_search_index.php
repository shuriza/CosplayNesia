<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement("CREATE VIRTUAL TABLE product_search USING fts5(product_id UNINDEXED, name, series, seller, city, tokenize='trigram')");
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
        DB::statement(<<<'SQL'
            INSERT INTO product_search(rowid, product_id, name, series, seller, city)
            SELECT id, id, coalesce(name, ''), coalesce(series, ''), coalesce(seller, ''), coalesce(city, '')
            FROM products
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS products_search_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS products_search_update');
        DB::unprepared('DROP TRIGGER IF EXISTS products_search_insert');
        DB::unprepared('DROP TABLE IF EXISTS product_search');
    }
};
