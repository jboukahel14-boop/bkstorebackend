<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    /**
     * List products for the admin catalog. Supports search + low-stock filter.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('category');

        if ($request->boolean('low_stock')) {
            $query->where('stock', '<', 5);
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('title_en', 'like', "%{$term}%")
                  ->orWhere('title_ar', 'like', "%{$term}%")
                  ->orWhere('slug', 'like', "%{$term}%");
            });
        }

        $perPage = min(max((int) $request->get('per_page', 50), 1), 100);
        $products = $query->latest()->paginate($perPage);

        return response()->json($products);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Product::with('category')->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|integer|exists:categories,id',
            'title_en' => 'required|string|max:255',
            'title_ar' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:products,slug',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string|max:1000',
            'is_featured' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['price'] = round((float) $data['price'], 2);

        if (isset($data['sale_price'])) {
            $data['sale_price'] = round((float) $data['sale_price'], 2);
        }

        $data['stock'] = $data['stock'] ?? 0;
        $data['is_featured'] = (bool) ($data['is_featured'] ?? false);

        if (empty($data['slug'] ?? '')) {
            $data['slug'] = Str::slug($data['title_en']) ?: null;
        }
        $data['slug'] = $data['slug'] ?? 'p-'.Str::lower(Str::random(6));
        if (Product::where('slug', $data['slug'])->exists()) {
            $data['slug'] .= '-'.Str::lower(Str::random(4));
        }

        $product = Product::create($data);

        return response()->json([
            'message' => 'Product created',
            'product' => $product->load('category'),
        ], 201);
    }

    /**
     * Full edit: all catalog fields plus quick inline price/stock changes.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'sometimes|integer|exists:categories,id',
            'title_en' => 'sometimes|string|max:255',
            'title_ar' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:products,slug,'.$id,
            'price' => 'sometimes|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock' => 'sometimes|integer|min:0',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string|max:1000',
            'is_featured' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product = Product::findOrFail($id);
        $data = $validator->validated();

        if (array_key_exists('slug', $data)) {
            if ($data['slug'] === null || trim($data['slug']) === '') {
                unset($data['slug']);
            }
        }

        if (array_key_exists('price', $data)) {
            $data['price'] = round((float) $data['price'], 2);
        }
        if (array_key_exists('sale_price', $data) && $data['sale_price'] !== null) {
            $data['sale_price'] = round((float) $data['sale_price'], 2);
        }

        $product->fill($data);
        $product->save();

        return response()->json([
            'message' => 'Product updated',
            'product' => $product->load('category'),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $title = $product->title_en;
        $product->delete();

        return response()->json(['message' => "'{$title}' deleted."]);
    }

    /**
     * Trigger a ConnectedDZ sync against the FlexDZ store API.
     */
    public function scrape(Request $request): JsonResponse
    {
        // Real catalog imports download dozens of images; a web request must
        // not be cut off mid-sync by PHP's max_execution_time.
        set_time_limit(0);

        $limit = min(max((int) $request->get('limit', 30), 1), 200);

        try {
            $options = ['--limit' => $limit];
            if ($request->filled('category')) {
                $options['--category'] = (string) $request->get('category');
            }

            $exitCode = Artisan::call('scrape:connected-dz', $options);
            $output = trim(Artisan::output());
            $summary = [
                'categories' => 0,
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'output' => $output,
            ];

            if (preg_match(
                '/\[summary\] categories=(\d+) imported=(\d+) updated=(\d+) skipped=(\d+) errors=(\d+)/',
                $output,
                $m
            )) {
                $summary = [
                    'categories' => (int) $m[1],
                    'imported' => (int) $m[2],
                    'updated' => (int) $m[3],
                    'skipped' => (int) $m[4],
                    'errors' => (int) $m[5],
                    'output' => $output,
                ];
            }

            return response()->json([
                'message' => $exitCode === 0 ? 'Sync completed' : 'Sync finished with warnings',
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            logger()->error('ConnectedDZ scrape failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Sync failed: '.$e->getMessage()], 500);
        }
    }
}