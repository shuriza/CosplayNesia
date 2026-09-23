<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('buyer')->index()->after('password');
        });

        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('slug', 100)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('city', 80);
            $table->string('logo_url', 2048)->nullable();
            $table->string('banner_url', 2048)->nullable();
            $table->string('operating_hours', 200)->nullable();
            $table->text('policy')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->string('legal_name', 120)->nullable();
            $table->string('identity_number_hash', 64)->nullable();
            $table->string('document_path', 500)->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->after('seller_id')->constrained()->nullOnDelete();
            $table->index(['store_id', 'is_active', 'created_at'], 'products_store_catalog_index');
        });

        $owners = DB::table('products')->whereNotNull('seller_id')->orderBy('seller_id')->get()->groupBy('seller_id');
        foreach ($owners as $sellerId => $products) {
            $first = $products->first();
            $base = Str::slug($first->seller ?: 'store-'.$sellerId) ?: 'store-'.$sellerId;
            $slug = $base.'-'.$sellerId;
            $storeId = DB::table('stores')->insertGetId([
                'user_id' => $sellerId,
                'slug' => $slug,
                'name' => $first->seller ?: 'Store '.$sellerId,
                'city' => $first->city ?: 'Online',
                'status' => 'approved',
                'submitted_at' => now(),
                'approved_at' => now(),
                'reviewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('products')->where('seller_id', $sellerId)->update(['store_id' => $storeId]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_store_catalog_index');
            $table->dropConstrainedForeignId('store_id');
        });
        Schema::dropIfExists('stores');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
