<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Support\VariantAvailability;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class SearchCatalog
{
    public const DEFAULT_PAGE_SIZE = 24;

    public const MAX_PAGE_SIZE = 100;

    public const MAX_QUERY_LENGTH = 80;

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     query: array{q: string, category: string, price_min: int|null, price_max: int|null, available: string, sort: string, page: int, page_size: int, facets: array<string, mixed>},
     *     products: list<array{id: string, slug: string, title: string, available: bool, availability: string, image: array{url: string, alt: string}, money: array{amount: int, currency: string, exponent: int}}>,
     *     meta: array{page: int, pageSize: int, totalItems: int, totalPages: int},
     *     facets: list<array{key: string, label: string, type: string, unit: string|null, values: list<array{value: string, count: int}>}>,
     *     categories: list<array{path: string, title: string}>,
     *     sorts: list<array{value: string, label: string}>
     * }
     */
    public function search(array $input): array
    {
        $definitions = $this->filterableDefinitions();
        $query = $this->sanitize($input, $definitions);
        $sorts = $this->sortOptions($definitions);
        $publicQuery = $query;
        if ($publicQuery['category'] === "\0") {
            $publicQuery['category'] = '';
        }
        $ids = $query['category'] === "\0" ? [] : $this->matchingIds($query, $definitions);
        $total = count($ids);
        $pageSize = $query['page_size'];
        $totalPages = $total === 0 ? 0 : (int) ceil($total / $pageSize);
        $offset = ($query['page'] - 1) * $pageSize;
        $pageIds = array_slice($ids, $offset, $pageSize);
        $products = $this->hydrate($pageIds);

        return [
            'query' => $publicQuery,
            'products' => $products,
            'meta' => [
                'page' => $query['page'],
                'pageSize' => $pageSize,
                'totalItems' => $total,
                'totalPages' => $totalPages,
            ],
            'facets' => $this->facetCards($query, $definitions),
            'categories' => $this->publishedCategories(),
            'sorts' => $sorts,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, array{id: string, key: string, type: string, unit: string|null, label: string}>  $definitions
     * @return array{q: string, category: string, price_min: int|null, price_max: int|null, available: string, sort: string, page: int, page_size: int, facets: array<string, mixed>}
     */
    private function sanitize(array $input, array $definitions): array
    {
        $q = is_string($input['q'] ?? null) ? trim($input['q']) : '';
        if ($q === '' || mb_strlen($q, 'UTF-8') > self::MAX_QUERY_LENGTH || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $q) === 1) {
            $q = '';
        }
        $requestedCategory = is_string($input['category'] ?? null) ? $input['category'] : '';
        $category = '';
        if ($requestedCategory !== '') {
            $category = $this->publishedCategoryPath($requestedCategory) ? $requestedCategory : "\0";
        }
        $priceMin = $this->optionalMoney($input['price_min'] ?? null);
        $priceMax = $this->optionalMoney($input['price_max'] ?? null);
        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            $priceMin = null;
            $priceMax = null;
        }
        $available = $input['available'] ?? '';
        $available = in_array($available, ['1', '0', 1, 0], true) ? (string) (int) $available : '';
        $sorts = array_column($this->sortOptions($definitions), 'value');
        $sort = is_string($input['sort'] ?? null) && in_array($input['sort'], $sorts, true) ? $input['sort'] : 'title';
        $page = is_numeric($input['page'] ?? null) ? (int) $input['page'] : 1;
        if ($page < 1) {
            $page = 1;
        }
        $pageSize = is_numeric($input['page_size'] ?? null) ? (int) $input['page_size'] : self::DEFAULT_PAGE_SIZE;
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }
        $facets = [];
        $raw = $input['f'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (! is_string($key) || ! isset($definitions[$key])) {
                    continue;
                }
                $parsed = $this->sanitizeFacetValue($definitions[$key], $value);
                if ($parsed !== null) {
                    $facets[$key] = $parsed;
                }
            }
        }

        return [
            'q' => $q,
            'category' => $category,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'available' => $available,
            'sort' => $sort,
            'page' => $page,
            'page_size' => $pageSize,
            'facets' => $facets,
        ];
    }

    /**
     * @param  array{id: string, key: string, type: string, unit: string|null, label: string}  $definition
     */
    private function sanitizeFacetValue(array $definition, mixed $value): mixed
    {
        if (in_array($definition['type'], ['integer', 'decimal', 'date'], true)) {
            if (! is_array($value)) {
                return null;
            }
            $min = is_string($value['min'] ?? null) ? $value['min'] : '';
            $max = is_string($value['max'] ?? null) ? $value['max'] : '';
            $range = [];
            if ($definition['type'] === 'date') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $min) === 1) {
                    $range['min'] = $min;
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $max) === 1) {
                    $range['max'] = $max;
                }
            } elseif ($definition['type'] === 'integer') {
                if (preg_match('/^(?:0|[1-9]\d{0,11})$/D', $min) === 1) {
                    $range['min'] = $min;
                }
                if (preg_match('/^(?:0|[1-9]\d{0,11})$/D', $max) === 1) {
                    $range['max'] = $max;
                }
            } else {
                if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,6})?$/D', $min) === 1) {
                    $range['min'] = $min;
                }
                if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,6})?$/D', $max) === 1) {
                    $range['max'] = $max;
                }
            }

            return $range === [] ? null : $range;
        }
        if (! is_string($value) || $value === '' || mb_strlen($value, 'UTF-8') > 80) {
            return null;
        }
        if ($definition['type'] === 'boolean' && ! in_array($value, ['1', '0'], true)) {
            return null;
        }
        if ($definition['type'] === 'enum') {
            $options = DB::table('attribute_enum_options')->where('attribute_definition_id', $definition['id'])->pluck('option_value')->all();
            if (! in_array($value, $options, true)) {
                return null;
            }
        }

        return $value;
    }

    private function optionalMoney(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0 && $value <= 9007199254740991) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9]\d{0,14})$/D', $value) === 1) {
            $amount = (int) $value;

            return $amount <= 9007199254740991 ? $amount : null;
        }

        return null;
    }

    private function publishedCategoryPath(string $path): bool
    {
        if (preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*){0,4}$#D', $path) !== 1) {
            return false;
        }
        $row = DB::table('categories')->where('path', $path)->where('status', 'published')->first();

        return $row !== null && $this->lineageIsPublished($path);
    }

    /**
     * @param  array{q: string, category: string, price_min: int|null, price_max: int|null, available: string, sort: string, page: int, page_size: int, facets: array<string, mixed>}  $query
     * @param  array<string, array{id: string, key: string, type: string, unit: string|null, label: string}>  $definitions
     * @return list<string>
     */
    private function matchingIds(array $query, array $definitions, ?string $exceptFacet = null): array
    {
        $builder = $this->publishedBase();
        if ($query['q'] !== '') {
            $like = '%'.addcslashes($query['q'], '\\%_').'%';
            $builder->where(function (Builder $group) use ($like): void {
                $group->where('products.title', 'like', $like)
                    ->orWhereExists(function (Builder $sku) use ($like): void {
                        $sku->selectRaw('1')
                            ->from('product_variants as sku_variants')
                            ->whereColumn('sku_variants.product_id', 'products.id')
                            ->where('sku_variants.sku', 'like', $like);
                    });
            });
        }
        if ($query['category'] !== '') {
            $paths = DB::table('categories')
                ->where('status', 'published')
                ->where(function (Builder $group) use ($query): void {
                    $group->where('path', $query['category'])->orWhere('path', 'like', $query['category'].'/%');
                })
                ->pluck('id')
                ->all();
            $builder->whereExists(function (Builder $membership) use ($paths): void {
                $membership->selectRaw('1')
                    ->from('product_categories')
                    ->whereColumn('product_categories.product_id', 'products.id')
                    ->whereIn('product_categories.category_id', $paths === [] ? [''] : $paths);
            });
        }
        if ($query['price_min'] !== null) {
            $builder->where('default_variants.price_amount', '>=', $query['price_min']);
        }
        if ($query['price_max'] !== null) {
            $builder->where('default_variants.price_amount', '<=', $query['price_max']);
        }
        if ($query['available'] === '1') {
            $builder->whereExists(function (Builder $stock): void {
                $this->availableVariantConstraint($stock);
            });
        } elseif ($query['available'] === '0') {
            $builder->whereNotExists(function (Builder $stock): void {
                $this->availableVariantConstraint($stock);
            });
        }
        foreach ($query['facets'] as $key => $value) {
            if ($exceptFacet !== null && $key === $exceptFacet) {
                continue;
            }
            $definition = $definitions[$key];
            $builder->whereExists(function (Builder $attribute) use ($definition, $value): void {
                $attribute->selectRaw('1')
                    ->from('attribute_values')
                    ->whereColumn('attribute_values.product_id', 'products.id')
                    ->where('attribute_values.attribute_definition_id', $definition['id']);
                $this->applyTypedValue($attribute, $definition['type'], $value);
            });
        }
        $ids = [];
        foreach ($builder->select('products.id')->distinct()->pluck('id') as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $this->orderedIds($ids, $query['sort'], $definitions);
    }

    private function availableVariantConstraint(Builder $stock): void
    {
        $stock->selectRaw('1')
            ->from('product_variants as sale_variants')
            ->leftJoin('inventory_balances', 'sale_variants.id', '=', 'inventory_balances.variant_id')
            ->whereColumn('sale_variants.product_id', 'products.id')
            ->where('sale_variants.available', '=', true)
            ->where(function (Builder $group): void {
                $group->where(function (Builder $physical): void {
                    $physical->where('products.type', '=', 'physical')
                        ->where(function (Builder $stock): void {
                            $stock->where('sale_variants.tracks_inventory', '=', false)
                                ->orWhere(function (Builder $tracked): void {
                                    $tracked->where('sale_variants.tracks_inventory', '=', true)
                                        ->whereNotNull('inventory_balances.on_hand')
                                        ->whereRaw('inventory_balances.on_hand > inventory_balances.reserved');
                                });
                        });
                })->orWhere(function (Builder $digital): void {
                    $digital->where('products.type', '=', 'digital')
                        ->whereExists(function (Builder $files): void {
                            $files->selectRaw('1')
                                ->from('variant_digital_assets')
                                ->join('digital_asset_versions', 'variant_digital_assets.digital_asset_version_id', '=', 'digital_asset_versions.id')
                                ->whereColumn('variant_digital_assets.variant_id', 'sale_variants.id')
                                ->where('digital_asset_versions.status', '=', 'ready');
                        });
                });
            });
    }

    private function applyTypedValue(Builder $attribute, string $type, mixed $value): void
    {
        if ($type === 'enum' && is_string($value)) {
            $attribute->where('attribute_values.enum_value', $value);
        } elseif ($type === 'boolean' && is_string($value)) {
            $attribute->where('attribute_values.boolean_value', $value === '1');
        } elseif ($type === 'string' && is_string($value)) {
            $attribute->where('attribute_values.string_value', $value);
        } elseif (is_array($value)) {
            $column = match ($type) {
                'integer' => 'attribute_values.integer_value',
                'decimal' => 'attribute_values.decimal_value',
                default => 'attribute_values.date_value',
            };
            if (isset($value['min']) && is_string($value['min'])) {
                $attribute->where($column, '>=', $value['min']);
            }
            if (isset($value['max']) && is_string($value['max'])) {
                $attribute->where($column, '<=', $value['max']);
            }
        }
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, array{id: string, key: string, type: string, unit: string|null, label: string}>  $definitions
     * @return list<string>
     */
    private function orderedIds(array $ids, string $sort, array $definitions): array
    {
        if ($ids === []) {
            return [];
        }
        $builder = DB::table('products')
            ->join('product_variants as default_variants', function ($join): void {
                $join->on('products.id', '=', 'default_variants.product_id')->where('default_variants.is_default', '=', true);
            })
            ->whereIn('products.id', $ids);
        $descending = str_ends_with($sort, '_desc');
        $key = $descending ? substr($sort, 0, -5) : $sort;
        if ($key === 'price') {
            $builder->orderBy('default_variants.price_amount', $descending ? 'desc' : 'asc');
        } elseif ($key === 'title') {
            $builder->orderBy('products.title', $descending ? 'desc' : 'asc');
        } elseif (isset($definitions[$key]) && in_array($definitions[$key]['type'], ['integer', 'decimal', 'date'], true)) {
            $column = match ($definitions[$key]['type']) {
                'integer' => 'attribute_values.integer_value',
                'decimal' => 'attribute_values.decimal_value',
                default => 'attribute_values.date_value',
            };
            $builder->leftJoin('attribute_values', function ($join) use ($definitions, $key): void {
                $join->on('attribute_values.product_id', '=', 'products.id')
                    ->where('attribute_values.attribute_definition_id', '=', $definitions[$key]['id']);
            });
            $builder->orderByRaw($column.' IS NULL');
            $builder->orderBy($column, $descending ? 'desc' : 'asc');
        } else {
            $builder->orderBy('products.title');
        }
        $ordered = [];
        foreach ($builder->orderBy('products.id')->select('products.id')->pluck('id') as $id) {
            if (is_string($id)) {
                $ordered[] = $id;
            }
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{id: string, slug: string, title: string, available: bool, availability: string, image: array{url: string, alt: string}, money: array{amount: int, currency: string, exponent: int}}>
     */
    private function hydrate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $records = $this->publishedBase()
            ->whereIn('products.id', $ids)
            ->select([
                'products.id', 'products.slug', 'products.title', 'default_variants.price_amount',
                'default_variants.currency', 'default_variants.exponent', 'product_media.alt', 'media_assets.public_key',
            ])
            ->get()
            ->keyBy('id');
        $products = [];
        foreach ($ids as $id) {
            $product = $records->get($id);
            if (! is_object($product)) {
                continue;
            }
            $publicKey = (string) $product->public_key;
            if (! preg_match('#^media/[0-9a-f-]{36}/[a-f0-9]{64}\.png$#D', $publicKey)) {
                continue;
            }
            $products[] = [
                'id' => (string) $product->id,
                'slug' => (string) $product->slug,
                'title' => (string) $product->title,
                'image' => ['url' => '/storage/'.$publicKey, 'alt' => (string) $product->alt],
                'money' => [
                    'amount' => (int) $product->price_amount,
                    'currency' => (string) $product->currency,
                    'exponent' => (int) $product->exponent,
                ],
            ];
        }
        $availability = app(VariantAvailability::class)->forProducts(array_column($products, 'id'));
        foreach ($products as $index => $product) {
            $summary = $availability[$product['id']] ?? ['available' => false, 'availability' => VariantAvailability::UNAVAILABLE];
            $products[$index]['available'] = $summary['available'];
            $products[$index]['availability'] = $summary['availability'];
        }

        return $products;
    }

    /**
     * @param  array{q: string, category: string, price_min: int|null, price_max: int|null, available: string, sort: string, page: int, page_size: int, facets: array<string, mixed>}  $query
     * @param  array<string, array{id: string, key: string, type: string, unit: string|null, label: string}>  $definitions
     * @return list<array{key: string, label: string, type: string, unit: string|null, values: list<array{value: string, count: int}>}>
     */
    private function facetCards(array $query, array $definitions): array
    {
        $facets = [];
        foreach ($definitions as $definition) {
            if (! in_array($definition['type'], ['enum', 'boolean', 'string'], true)) {
                $facets[] = [
                    'key' => $definition['key'],
                    'label' => $definition['label'],
                    'type' => $definition['type'],
                    'unit' => $definition['unit'],
                    'values' => [],
                ];

                continue;
            }
            $ids = $this->matchingIds($query, $definitions, $definition['key']);
            $column = match ($definition['type']) {
                'enum' => 'enum_value',
                'boolean' => 'boolean_value',
                default => 'string_value',
            };
            $counts = [];
            if ($ids !== []) {
                $rows = DB::table('attribute_values')
                    ->where('attribute_definition_id', $definition['id'])
                    ->whereIn('product_id', $ids)
                    ->select($column.' as value', DB::raw('COUNT(DISTINCT product_id) as aggregate'))
                    ->groupBy($column)
                    ->orderBy($column)
                    ->get();
                foreach ($rows as $row) {
                    if ($row->value === null) {
                        continue;
                    }
                    $value = $definition['type'] === 'boolean' ? ((bool) $row->value ? '1' : '0') : (string) $row->value;
                    $counts[] = ['value' => $value, 'count' => (int) $row->aggregate];
                }
            }
            $facets[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'type' => $definition['type'],
                'unit' => $definition['unit'],
                'values' => $counts,
            ];
        }

        return $facets;
    }

    /**
     * @return array<string, array{id: string, key: string, type: string, unit: string|null, label: string}>
     */
    private function filterableDefinitions(): array
    {
        $definitions = [];
        $rows = DB::table('attribute_definitions')
            ->join('product_schema_version_fields', 'attribute_definitions.id', '=', 'product_schema_version_fields.attribute_definition_id')
            ->join('products', 'products.schema_version_id', '=', 'product_schema_version_fields.schema_version_id')
            ->where('attribute_definitions.filterable', true)
            ->where('products.status', 'published')
            ->orderBy('attribute_definitions.semantic_key')
            ->select([
                'attribute_definitions.id', 'attribute_definitions.semantic_key', 'attribute_definitions.type',
                'attribute_definitions.unit', 'product_schema_version_fields.label',
            ])
            ->distinct()
            ->get();
        foreach ($rows as $row) {
            $key = (string) $row->semantic_key;
            $definitions[$key] = [
                'id' => (string) $row->id,
                'key' => $key,
                'type' => (string) $row->type,
                'unit' => is_string($row->unit) ? $row->unit : null,
                'label' => (string) $row->label,
            ];
        }

        return $definitions;
    }

    /**
     * @param  array<string, array{id: string, key: string, type: string, unit: string|null, label: string}>  $definitions
     * @return list<array{value: string, label: string}>
     */
    private function sortOptions(array $definitions): array
    {
        $sorts = [
            ['value' => 'title', 'label' => 'Title'],
            ['value' => 'title_desc', 'label' => 'Title (Z–A)'],
            ['value' => 'price', 'label' => 'Price'],
            ['value' => 'price_desc', 'label' => 'Price (high to low)'],
        ];
        foreach ($definitions as $definition) {
            if (! in_array($definition['type'], ['integer', 'decimal', 'date'], true)) {
                continue;
            }
            $sorts[] = ['value' => $definition['key'], 'label' => $definition['label']];
            $sorts[] = ['value' => $definition['key'].'_desc', 'label' => $definition['label'].' (high to low)'];
        }

        return $sorts;
    }

    /** @return list<array{path: string, title: string}> */
    private function publishedCategories(): array
    {
        $categories = [];
        foreach (DB::table('categories')->where('status', 'published')->orderBy('path')->get() as $row) {
            $path = (string) $row->path;
            if (! $this->lineageIsPublished($path)) {
                continue;
            }
            $categories[] = ['path' => $path, 'title' => (string) $row->title];
        }

        return $categories;
    }

    private function publishedBase(): Builder
    {
        return DB::table('products')
            ->join('product_variants as default_variants', function ($join): void {
                $join->on('products.id', '=', 'default_variants.product_id')->where('default_variants.is_default', '=', true);
            })
            ->join('product_media', function ($join): void {
                $join->on('products.id', '=', 'product_media.product_id')->where('product_media.sort_order', '=', 0);
            })
            ->join('media_assets', 'product_media.media_asset_id', '=', 'media_assets.id')
            ->where('products.status', 'published')
            ->where('media_assets.kind', 'public_image')
            ->where('media_assets.status', 'ready');
    }

    private function lineageIsPublished(string $path): bool
    {
        $segments = explode('/', $path);
        $paths = [];
        $prefix = '';
        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix.'/'.$segment;
            $paths[] = $prefix;
        }
        $count = DB::table('categories')->whereIn('path', $paths)->where('status', 'published')->count();

        return $count === count($paths);
    }
}
