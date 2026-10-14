<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Restores the SQLite FTS5 synchronization triggers on `products`.
 *
 * Any migration that rebuilds the `products` table drops these triggers, because SQLite implements
 * a table rebuild as create-new / copy / drop-old / rename, and triggers do not survive it. A
 * rebuild happens for changes SQLite cannot do in place — adding a foreign key, dropping a column,
 * altering a column type — which is easy to do by accident: adding `->constrained()` to a new
 * column on `products` is enough.
 *
 * Without the triggers the catalog silently stops matching new or edited rows: search returns stale
 * results with no error anywhere. That is why this is called explicitly by every migration that
 * touches the table, and why the backfill runs too — rows written while the triggers were missing
 * would otherwise stay invisible forever.
 *
 * On drivers other than SQLite this is a no-op: PostgreSQL keeps its own search column up to date.
 */
class ProductSearchIndex
{
    public static function restore(): void
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
}
