<?php

namespace App\Http\Controllers\Api\V1\Bar;

use App\Domain\Audit\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Bar\BarProductResource;
use App\Models\BarCategory;
use App\Models\BarProduct;
use App\Models\Property;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Bar menu: categories and products (P11: no stock yet, only sold-out switch). */
class CatalogController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /** Waiter menu: active categories with their active products. */
    public function menu(): JsonResponse
    {
        $categories = BarCategory::query()
            ->where('property_id', Property::current()->id)
            ->where('is_active', true)
            ->with(['products' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($categories->map(fn (BarCategory $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'products' => BarProductResource::collection($c->products)->resolve(request()),
        ])->values());
    }

    public function categories(): JsonResponse
    {
        $list = BarCategory::query()->where('property_id', Property::current()->id)->withCount('products')->orderBy('sort_order')->orderBy('name')->get();

        return ApiResponse::success($list->map(fn (BarCategory $c) => [
            'id' => $c->id, 'name' => $c->name, 'sort_order' => $c->sort_order, 'is_active' => $c->is_active, 'products_count' => $c->products_count,
        ]));
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('bar_categories')->where('property_id', Property::current()->id)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $category = BarCategory::create([...$data, 'property_id' => Property::current()->id]);
        $this->audit->record('bar.category_created', $category, null, ['name' => $category->name]);

        return ApiResponse::created(['id' => $category->id, 'name' => $category->name, 'sort_order' => $category->sort_order, 'is_active' => true], 'Category added.');
    }

    public function updateCategory(Request $request, int $category): JsonResponse
    {
        $model = BarCategory::query()->where('property_id', Property::current()->id)->findOrFail($category);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80', Rule::unique('bar_categories')->where('property_id', Property::current()->id)->ignore($model->id)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->fill($data)->save();

        return ApiResponse::success(['id' => $model->id, 'name' => $model->name, 'sort_order' => $model->sort_order, 'is_active' => $model->is_active], 'Category saved.');
    }

    public function destroyCategory(int $category): JsonResponse
    {
        // Soft-deleted products still reference the category (FK restrict), so count them too.
        $model = BarCategory::query()->where('property_id', Property::current()->id)
            ->withCount(['products' => fn ($q) => $q->withTrashed()])
            ->findOrFail($category);

        if ($model->products_count > 0) {
            throw new BusinessRuleException('Move or remove its products first, or switch the category off.', 'CATEGORY_IN_USE', 422);
        }

        $model->delete();

        return ApiResponse::success(null, 'Category removed.');
    }

    public function products(Request $request): JsonResponse
    {
        $list = BarProduct::query()
            ->where('property_id', Property::current()->id)
            ->with('category')
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->orderBy('category_id')->orderBy('sort_order')->orderBy('name')
            ->get();

        return ApiResponse::success(BarProductResource::collection($list));
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $this->validateProduct($request);
        $product = BarProduct::create([...$data, 'property_id' => Property::current()->id]);
        $this->audit->record('bar.product_created', $product, null, $product->only(['name', 'price']));

        return ApiResponse::created(new BarProductResource($product->load('category')), 'Product added.');
    }

    public function updateProduct(Request $request, int $product): JsonResponse
    {
        $model = $this->product($product);
        $data = $this->validateProduct($request, $model);
        $before = $model->only(array_keys($data));
        $model->fill($data)->save();
        [$old, $new] = $this->audit->diff($before, $model->only(array_keys($data)));

        if ($new !== []) {
            $this->audit->record('bar.product_updated', $model, $old, $new);
        }

        return ApiResponse::success(new BarProductResource($model->load('category')), 'Product saved.');
    }

    /** Sold out / back on - bartenders can do this too. */
    public function availability(Request $request, int $product): JsonResponse
    {
        $model = $this->product($product);
        $model->forceFill(['is_available' => $request->validate(['is_available' => ['required', 'boolean']])['is_available']])->save();
        $this->audit->record('bar.product_availability', $model, null, ['is_available' => $model->is_available]);

        return ApiResponse::success(new BarProductResource($model->load('category')), $model->is_available ? 'Back on the menu.' : 'Marked sold out.');
    }

    public function destroyProduct(int $product): JsonResponse
    {
        $model = $this->product($product);
        $model->delete();
        $this->audit->record('bar.product_deleted', $model, $model->only(['name', 'price']), null);

        return ApiResponse::success(null, 'Product removed.');
    }

    private function product(int $id): BarProduct
    {
        return BarProduct::query()->where('property_id', Property::current()->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validateProduct(Request $request, ?BarProduct $model = null): array
    {
        $req = $model ? 'sometimes' : 'required';
        $propertyId = Property::current()->id;

        $data = $request->validate([
            'category_id' => [$req, 'integer', Rule::exists('bar_categories', 'id')->where('property_id', $propertyId)],
            'name' => [$req, 'string', 'max:120', Rule::unique('bar_products')->where('property_id', $propertyId)->ignore($model?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => [$req, 'string', 'regex:/^\d{1,11}(\.\d{1,2})?$/'],
            'is_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        if (isset($data['price'])) {
            $data['price'] = Money::toDecimal(Money::toMinor($data['price']));
        }

        return $data;
    }
}
