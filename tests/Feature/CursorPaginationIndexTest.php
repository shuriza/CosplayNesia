<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CursorPaginationIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_cursor_list_queries_use_scope_and_order_indexes_without_temporary_sorting(): void
    {
        $this->assertIndexedPlan(
            'SELECT * FROM products WHERE seller_id = ? ORDER BY created_at DESC, id DESC LIMIT 5',
            [1],
            'products_seller_cursor_index',
        );
        $this->assertIndexedPlan(
            'SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 5',
            [1],
            'orders_user_cursor_index',
        );
        $this->assertIndexedPlan(
            'SELECT * FROM order_fulfillments WHERE seller_id = ? ORDER BY created_at DESC, id DESC LIMIT 5',
            [1],
            'fulfillments_seller_cursor_index',
        );
        $this->assertIndexedPlan(
            'SELECT * FROM order_fulfillments WHERE seller_id = ? AND status = ? ORDER BY created_at DESC, id DESC LIMIT 5',
            [1, 'received'],
            'fulfillments_seller_status_cursor_index',
        );
        $this->assertIndexedPlan(
            'SELECT * FROM products WHERE is_active = ? ORDER BY popular DESC, id DESC LIMIT 8',
            [1],
            'products_active_popular_cursor_index',
        );
        $this->assertIndexedPlan(
            'SELECT * FROM products WHERE is_active = ? ORDER BY created_at DESC, id DESC LIMIT 8',
            [1],
            'products_active_created_cursor_index',
        );
        $this->assertIndexedPlan(
            'SELECT * FROM products WHERE is_active = ? ORDER BY price ASC, id ASC LIMIT 8',
            [1],
            'products_active_price_cursor_index',
        );
        $this->assertIndexedPlan(
            'SELECT * FROM products WHERE is_active = ? ORDER BY price DESC, id DESC LIMIT 8',
            [1],
            'products_active_price_cursor_index',
        );
    }

    public function test_long_catalog_search_uses_fts_virtual_index_instead_of_scanning_products(): void
    {
        $details = collect(DB::select(<<<'SQL'
            EXPLAIN QUERY PLAN
            SELECT products.* FROM products
            WHERE is_active = 1
              AND products.id IN (
                  SELECT product_id FROM product_search WHERE product_search MATCH ?
              )
            ORDER BY popular DESC, id DESC
            LIMIT 8
            SQL, ['"Fur"']))->pluck('detail')->implode(' | ');

        $this->assertStringContainsString('SCAN product_search VIRTUAL TABLE INDEX', $details);
        $this->assertStringNotContainsString('SCAN products', $details);
    }

    private function assertIndexedPlan(string $query, array $bindings, string $index): void
    {
        $details = collect(DB::select('EXPLAIN QUERY PLAN '.$query, $bindings))
            ->pluck('detail')
            ->implode(' | ');

        $this->assertStringContainsString("USING INDEX {$index}", $details);
        $this->assertStringNotContainsString('SCAN ', $details);
        $this->assertStringNotContainsString('USE TEMP B-TREE', $details);
    }
}
