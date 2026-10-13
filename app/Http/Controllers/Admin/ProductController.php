<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ReadCatalog;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Exceptions\CatalogProductNotFound;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffMediaUploadAuthorization;
use App\Domain\Platform\Actions\ProcessMedia;
use App\Domain\Platform\Actions\StoreMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ProductController
{
    public function index(Request $request): Response
    {
        Gate::authorize('catalog.read');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $page = DB::table('products')->orderByDesc('updated_at')->orderBy('id')->paginate(24);
        $data = [];
        foreach ($page->getCollection() as $product) {
            $row = is_object($product) ? get_object_vars($product) : [];
            $id = is_string($row['id'] ?? null) ? $row['id'] : '';
            $title = is_string($row['title'] ?? null) ? $row['title'] : '';
            $slug = is_string($row['slug'] ?? null) ? $row['slug'] : '';
            $status = is_string($row['status'] ?? null) ? $row['status'] : '';
            $updated = $row['updated_at'] ?? '';
            $data[] = [
                'id' => $id, 'title' => $title, 'slug' => $slug, 'status' => $status,
                'updated_at' => is_string($updated) ? $updated : (string) $updated,
            ];
        }

        return Inertia::render('ProductIndex', [
            'products' => [
                'data' => $data,
                'next_page_url' => $page->nextPageUrl(),
                'prev_page_url' => $page->previousPageUrl(),
            ],
            'canWrite' => $staff->hasPermission('catalog.write'),
        ]);
    }

    public function create(ReadCatalog $catalog): Response
    {
        Gate::authorize('catalog.write');

        return Inertia::render('ProductEdit', [
            'product' => null,
            'categories' => $catalog->staffCategories(),
            'schemas' => $catalog->staffSchemas(),
            'saved' => session()->get('product_saved', false),
        ]);
    }

    public function edit(string $productId, ReadCatalog $catalog): Response
    {
        Gate::authorize('catalog.write');
        try {
            $product = $catalog->staffProduct($productId);
        } catch (CatalogProductNotFound) {
            abort(404);
        }

        return Inertia::render('ProductEdit', [
            'product' => $product,
            'categories' => $catalog->staffCategories(),
            'schemas' => $catalog->staffSchemas(),
            'saved' => session()->get('product_saved', false),
        ]);
    }

    public function store(Request $request, SaveProduct $save, StoreMedia $media, ProcessMedia $processor): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $this->validated($this->hydrateProduct($request), false);
        $input['image_asset_id'] = $this->storeImage($request, $media, $processor);

        return $this->persist($request, $save, $staff, $input, '/admin/products/create');
    }

    public function update(Request $request, string $productId, SaveProduct $save, StoreMedia $media, ProcessMedia $processor, ReadCatalog $catalog): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $this->validated($this->hydrateProduct($request), true);
        $input['id'] = $productId;
        if ($request->file('image') !== null) {
            $input['image_asset_id'] = $this->storeImage($request, $media, $processor);
        } else {
            try {
                $input['image_asset_id'] = $catalog->staffProduct($productId)['image']['assetId'];
            } catch (CatalogProductNotFound) {
                abort(404);
            }
        }

        return $this->persist($request, $save, $staff, $input, '/admin/products/'.$productId);
    }

    private function hydrateProduct(Request $request): Request
    {
        $product = $request->input('product');
        if (is_string($product) && $product !== '') {
            $decoded = json_decode($product, true);
            $product = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($product)) {
            $product = $request->only(['title', 'slug', 'description', 'status', 'sku', 'price_amount', 'image_alt', 'version', 'category_ids', 'schema_id', 'attributes']);
        }
        if (($product['version'] ?? '') === '' || $product['version'] === null) {
            unset($product['version']);
        }
        if (($product['schema_id'] ?? null) === '') {
            $product['schema_id'] = null;
        }
        $request->merge(['product' => $product]);

        return $request;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $update): array
    {
        $keys = 'title,slug,description,status,sku,price_amount,image_alt,category_ids,version,schema_id,attributes';
        $rules = [
            'product' => ['required', 'array:'.$keys],
            'product.title' => ['required', 'string', 'max:200'],
            'product.slug' => ['required', 'string', 'max:80'],
            'product.description' => ['required', 'string', 'max:5000'],
            'product.status' => ['required', 'string'],
            'product.sku' => ['required', 'string', 'max:64'],
            'product.price_amount' => ['required', 'integer'],
            'product.image_alt' => ['required', 'string', 'max:200'],
            'product.category_ids' => ['sometimes', 'array', 'max:20'],
            'product.category_ids.*' => ['uuid'],
            'product.schema_id' => ['nullable', 'uuid'],
            'product.attributes' => ['sometimes', 'array', 'max:30'],
            'product.version' => [$update ? 'required' : 'sometimes', 'nullable', 'integer'],
            'image' => [$update ? 'nullable' : 'required', 'file'],
        ];

        return $request->validate($rules)['product'];
    }

    private function storeImage(Request $request, StoreMedia $media, ProcessMedia $processor): string
    {
        $file = $request->file('image');
        abort_unless($file !== null, 422);
        $id = $media->store($file, 'public_image', app(StaffMediaUploadAuthorization::class));
        $processor->process($id);

        return $id;
    }

    /** @param array<string, mixed> $input */
    private function persist(Request $request, SaveProduct $save, StaffAccount $staff, array $input, string $fallback): RedirectResponse
    {
        try {
            $saved = $save->save($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['product' => $exception->getMessage()])->redirectTo($fallback);
            }
            throw $exception;
        }

        return redirect('/admin/products/'.$saved['id'])->with('product_saved', true);
    }
}
