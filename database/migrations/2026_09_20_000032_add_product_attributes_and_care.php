<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('category_attributes')->nullable()->after('category');
            $table->json('costume_includes')->nullable()->after('category_attributes');
            $table->json('measurements')->nullable()->after('size');
            $table->text('care_instructions')->nullable()->after('measurements');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['category_attributes', 'costume_includes', 'measurements', 'care_instructions']);
        });
    }
};
