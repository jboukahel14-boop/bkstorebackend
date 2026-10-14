<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminCategoryController extends Controller
{
    /**
     * List categories with product counts for the admin catalog page.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::withCount('products');

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('name_en', 'like', "%{$term}%")
                  ->orWhere('name_ar', 'like', "%{$term}%")
                  ->orWhere('slug', 'like', "%{$term}%");
            });
        }

        $perPage = min(max((int) $request->get('per_page', 50), 1), 100);
        $categories = $query->orderBy('name_en')->paginate($perPage);

        return response()->json($categories);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Category::withCount('products')->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name_en' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug',
            'image_url' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['slug'] = $data['slug'] ?? Str::slug($data['name_en']) ?: '';
        if ($data['slug'] === '') {
            $data['slug'] = 'cat-'.Str::lower(Str::random(6));
        }
        if (Category::where('slug', $data['slug'])->exists()) {
            $data['slug'] .= '-'.Str::lower(Str::random(4));
        }

        $category = Category::create($data);

        return response()->json([
            'message' => 'Category created',
            'category' => $category->loadCount('products'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name_en' => 'sometimes|string|max:255',
            'name_ar' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug,'.$id,
            'image_url' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $category = Category::findOrFail($id);
        $data = $validator->validated();

        if (array_key_exists('slug', $data) && ($data['slug'] === null || $data['slug'] === '')) {
            unset($data['slug']);
        }

        $category->update($data);

        return response()->json([
            'message' => 'Category updated',
            'category' => $category->loadCount('products'),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::withCount('products')->findOrFail($id);
        $count = $category->products_count;
        $slug = $category->slug;

        $category->delete();

        return response()->json([
            'message' => "Category '{$slug}' deleted along with {$count} product(s).",
        ]);
    }
}