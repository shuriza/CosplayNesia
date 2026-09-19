<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS products_search_vector_index');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement(<<<'SQL'
            CREATE INDEX products_search_trigram_index ON products USING GIN (
                lower(coalesce(name, '') || ' ' || coalesce(series, '') || ' ' || coalesce(seller, '') || ' ' || coalesce(city, '')) gin_trgm_ops
            )
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS products_search_trigram_index');
        DB::statement(<<<'SQL'
            CREATE INDEX products_search_vector_index ON products USING GIN (
                to_tsvector(
                    'simple'::regconfig,
                    coalesce(name, '') || ' ' || coalesce(series, '') || ' ' || coalesce(seller, '') || ' ' || coalesce(city, '')
                )
            )
            SQL);
    }
};
