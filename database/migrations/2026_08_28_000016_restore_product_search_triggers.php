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
        // The search triggers remain valid when rolling back the unrelated products.newest removal.
    }
};
