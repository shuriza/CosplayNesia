<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('series', 100)->default('Original');
            $table->string('category', 30)->index();
            $table->unsignedBigInteger('price');
            $table->string('type', 10)->default('Sewa');
            $table->string('size', 30)->default('All size');
            $table->string('seller', 80);
            $table->string('city', 80)->default('Online');
            $table->decimal('rating', 2, 1)->default(5);
            $table->unsignedInteger('popular')->default(0)->index();
            $table->unsignedInteger('newest')->default(0)->index();
            $table->string('badge', 30)->nullable();
            $table->text('image');
            $table->unsignedInteger('stock')->default(1);
            $table->timestamps();
            $table->index(['name', 'series']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
