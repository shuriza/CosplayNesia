<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductGallery
{
    public const MAX_IMAGES = 8;

    /** @param array<int, UploadedFile> $files */
    public function add(Product $product, array $files): Product
    {
        if ($files === []) {
            return $this->load($product);
        }

        $disk = (string) config('cosplaynesia.listing_media_disk', 'public');
        $stored = [];

        try {
            foreach ($files as $file) {
                $path = $file->storePublicly("products/{$product->id}", $disk);
                if (! $path) {
                    throw ValidationException::withMessages(['images' => 'Gambar produk gagal disimpan.']);
                }
                $stored[] = [
                    'disk' => $disk,
                    'path' => $path,
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                ];
            }

            return DB::transaction(function () use ($product, $stored): Product {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $existing = $locked->images()->count();
                if ($existing + count($stored) > self::MAX_IMAGES) {
                    throw ValidationException::withMessages([
                        'images' => 'Galeri produk maksimal '.self::MAX_IMAGES.' gambar.',
                    ]);
                }

                foreach ($stored as $offset => $image) {
                    $locked->images()->create([...$image, 'position' => $existing + $offset]);
                }
                $this->syncPrimaryImage($locked);

                return $this->load($locked);
            }, 3);
        } catch (\Throwable $exception) {
            foreach ($stored as $image) {
                Storage::disk($image['disk'])->delete($image['path']);
            }
            throw $exception;
        }
    }

    /** @param array<int, int> $imageIds */
    public function reorder(Product $product, array $imageIds): Product
    {
        return DB::transaction(function () use ($product, $imageIds): Product {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $images = $locked->images()->orderBy('position')->lockForUpdate()->get();
            $current = $images->pluck('id')->map(fn ($id) => (int) $id)->all();
            $requested = array_map('intval', $imageIds);
            $expected = $current;
            sort($expected);
            $actual = $requested;
            sort($actual);

            if ($actual !== $expected) {
                throw ValidationException::withMessages(['image_ids' => 'Urutan harus memuat seluruh gambar produk tepat satu kali.']);
            }

            foreach ($images as $image) {
                $image->update(['position' => $image->position + 1000]);
            }
            foreach ($requested as $position => $id) {
                ProductImage::query()->whereKey($id)->where('product_id', $locked->id)->update(['position' => $position]);
            }
            $this->syncPrimaryImage($locked);

            return $this->load($locked);
        }, 3);
    }

    public function remove(Product $product, ProductImage $image): Product
    {
        [$disk, $path, $updated] = DB::transaction(function () use ($product, $image): array {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $lockedImage = $locked->images()->lockForUpdate()->findOrFail($image->id);
            $disk = $lockedImage->disk;
            $path = $lockedImage->path;
            $lockedImage->delete();

            $locked->images()->orderBy('position')->get()->each(
                fn (ProductImage $entry, int $position) => $entry->update(['position' => $position]),
            );
            $this->syncPrimaryImage($locked);

            return [$disk, $path, $this->load($locked)];
        }, 3);

        Storage::disk($disk)->delete($path);

        return $updated;
    }

    public function purge(Product $product): void
    {
        $product->images()->get()->each(
            fn (ProductImage $image) => Storage::disk($image->disk)->delete($image->path),
        );
    }

    private function syncPrimaryImage(Product $product): void
    {
        $primary = $product->images()->orderBy('position')->first();
        $product->update(['image' => $primary?->url ?? config('cosplaynesia.default_product_image')]);
    }

    private function load(Product $product): Product
    {
        return $product->newQuery()->with(['images', 'variants', 'store:id,slug,name,status'])->withReviewSummary()->findOrFail($product->id);
    }
}
