<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SavedSearch;
use App\Services\SavedSearchScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Saved catalog searches. The stored payload is the shopper's filter set exactly as the catalog
 * endpoint accepts it, so "save this search" and "run this search" cannot drift apart: the scanner
 * re-validates against the same rules and re-uses the same query shape.
 */
class SavedSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $searches = $request->user()->savedSearches()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $searches]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'filters' => ['required', 'array'],
            ...self::filterRules(),
        ]);

        $existing = (int) $request->user()->savedSearches()->count();
        abort_if($existing >= SavedSearch::MAX_PER_USER, 422, 'Maksimal '.SavedSearch::MAX_PER_USER.' pencarian tersimpan.');

        $search = DB::transaction(fn () => $request->user()->savedSearches()->create([
            'name' => $validated['name'],
            'filters' => $this->normalizedFilters($validated),
            'alert_enabled' => true,
        ]), 3);

        return response()->json($search, 201);
    }

    public function update(Request $request, SavedSearch $savedSearch, SavedSearchScanner $scanner): JsonResponse
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 403);

        if ($request->boolean('run')) {
            $result = $scanner->scanOne($savedSearch->fresh());

            return response()->json(['search' => $savedSearch->fresh(), 'result' => $result]);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'alert_enabled' => ['sometimes', 'boolean'],
        ]);
        $savedSearch->update($validated);

        return response()->json($savedSearch->fresh());
    }

    public function destroy(Request $request, SavedSearch $savedSearch): JsonResponse
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 403);
        $savedSearch->delete();

        return response()->json(null, 204);
    }

    /**
     * The saved filter set. Dates are allowed but stored as-is: the scanner re-validates them on
     * every run so an alert window saved months ago cannot silently keep matching.
     *
     * @return array<string, array<int, string>>
     */
    public static function filterRules(): array
    {
        return [
            'filters.q' => ['nullable', 'string', 'max:100'],
            'filters.category' => ['nullable', Rule::in(['Anime', 'Game', 'VTuber', 'Aksesoris'])],
            'filters.type' => ['nullable', Rule::in([Product::TYPE_RENTAL, Product::TYPE_SALE])],
            'filters.size' => ['nullable', 'string', 'max:40'],
            'filters.city' => ['nullable', 'string', 'max:80'],
            'filters.min_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'filters.max_price' => ['nullable', 'integer', 'min:0', 'max:100000000', 'gte:filters.min_price'],
            'filters.condition' => ['nullable', Rule::in(['new', 'like_new', 'good', 'fair'])],
            'filters.start_date' => ['nullable', 'date', 'required_with:filters.end_date'],
            'filters.end_date' => ['nullable', 'date', 'required_with:filters.start_date', 'after_or_equal:filters.start_date'],
            'filters.quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizedFilters(array $validated): array
    {
        return array_filter($validated['filters'] ?? [], fn ($value) => $value !== null && $value !== '');
    }
}
