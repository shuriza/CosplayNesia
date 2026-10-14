<?php

use App\Support\ProductSearchIndex;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ProductSearchIndex::restore();
    }

    public function down(): void
    {
        // Search triggers remain valid without products.store_id.
    }
};
