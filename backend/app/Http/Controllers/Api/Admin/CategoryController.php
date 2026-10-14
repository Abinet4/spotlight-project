<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\AuditLogger;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::withCount('products')->orderBy('parent_id')->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request);

        $category = new Category($data);
        $category->slug = Slug::unique(Category::class, $data['slug'] ?? $data['name']);
        $category->save();

        $audit->log('category.created', $category, ['name' => $category->name]);

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(Request $request, Category $category, AuditLogger $audit): CategoryResource
    {
        $data = $this->validated($request, $category);

        if (isset($data['parent_id']) && $category->children()->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'A category with subcategories cannot become a subcategory.']);
        }

        $category->fill(collect($data)->except('slug')->all());
        if (! empty($data['slug']) && $data['slug'] !== $category->slug) {
            $category->slug = Slug::unique(Category::class, $data['slug'], $category->id);
        }
        $changed = array_keys($category->getDirty());
        $category->save();

        $audit->log('category.updated', $category, ['fields' => $changed]);

        return new CategoryResource($category);
    }

    /**
     * Categories in use are deactivated instead of deleted so listings keep their history.
     */
    public function destroy(Category $category, AuditLogger $audit): JsonResponse
    {
        if ($category->products()->withTrashed()->exists() || $category->children()->exists()) {
            return response()->json([
                'message' => 'This category has listings or subcategories. Deactivate it instead.',
            ], 409);
        }

        $audit->log('category.deleted', $category, ['name' => $category->name]);
        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        $required = $category ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            // Only two levels: a parent must itself be top-level and cannot be the category itself.
            'parent_id' => ['nullable', 'integer',
                Rule::exists('categories', 'id')->whereNull('parent_id'),
                Rule::notIn(array_filter([$category?->id])),
            ],
        ]);
    }
}
