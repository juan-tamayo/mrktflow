<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Data;

use App\Domain\Platform\Support\SetupChoices;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonSerializable;

final readonly class SearchView implements JsonSerializable
{
    /**
     * @param  array{q: string, category: string, price_min: int|null, price_max: int|null, available: string, sort: string, page: int, page_size: int, facets: array<string, mixed>}  $query
     * @param  list<array{id: string, url: string, title: string, available: bool, availability: string, image: array{url: string, alt: string}, money: array{amountMinor: int, currency: string, exponent: int, formatted: string}}>  $products
     * @param  array{page: int, pageSize: int, totalItems: int, totalPages: int}  $meta
     * @param  list<array{key: string, label: string, type: string, unit: string|null, values: list<array{value: string, count: int}>}>  $facets
     * @param  list<array{path: string, title: string}>  $categories
     * @param  list<array{value: string, label: string}>  $sorts
     */
    private function __construct(
        public array $query,
        public array $products,
        public array $meta,
        public array $facets,
        public array $categories,
        public array $sorts,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $path = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('#^/(?!/)[A-Za-z0-9/_.-]*$#D', $value) || array_intersect(explode('/', $value), ['.', '..']) !== []) {
                $fail('Use a local root-relative path without traversal or URL parameters.');
            }
        };
        $data = Validator::make(['search' => $input], [
            'search' => ['required', 'array:query,products,meta,facets,categories,sorts'],
            'search.query' => ['required', 'array:q,category,price_min,price_max,available,sort,page,page_size,facets'],
            'search.query.q' => ['present', 'string', 'max:80'],
            'search.query.category' => ['present', 'string', 'max:200'],
            'search.query.price_min' => ['nullable', 'integer', 'min:0', 'max:9007199254740991'],
            'search.query.price_max' => ['nullable', 'integer', 'min:0', 'max:9007199254740991'],
            'search.query.available' => ['present', 'string', Rule::in(['', '0', '1'])],
            'search.query.sort' => ['required', 'string', 'max:80'],
            'search.query.page' => ['required', 'integer', 'min:1', 'max:100000'],
            'search.query.page_size' => ['required', 'integer', 'min:1', 'max:100'],
            'search.query.facets' => ['present', 'array', 'max:30'],
            'search.products' => ['present', 'array', 'list', 'max:100'],
            'search.products.*' => ['required', 'array:id,url,title,image,money,available,availability'],
            'search.products.*.id' => ['required', 'uuid', 'distinct:strict'],
            'search.products.*.url' => ['required', 'string', 'max:500', $path],
            'search.products.*.title' => ['required', 'string', 'max:200'],
            'search.products.*.available' => ['required', 'boolean'],
            'search.products.*.availability' => ['required', 'string', Rule::in(['in_stock', 'out_of_stock', 'unavailable'])],
            'search.products.*.image' => ['required', 'array:url,alt'],
            'search.products.*.image.url' => ['required', 'string', 'max:500', $path],
            'search.products.*.image.alt' => ['required', 'string', 'max:200'],
            'search.products.*.money' => ['required', 'array:amountMinor,currency,exponent'],
            'search.products.*.money.amountMinor' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'search.products.*.money.currency' => ['required', 'string', Rule::in(array_column(app(SetupChoices::class)->currencies(), 'code'))],
            'search.products.*.money.exponent' => ['required', 'integer', 'min:0', 'max:3'],
            'search.meta' => ['required', 'array:page,pageSize,totalItems,totalPages'],
            'search.meta.page' => ['required', 'integer', 'min:1'],
            'search.meta.pageSize' => ['required', 'integer', 'min:1', 'max:100'],
            'search.meta.totalItems' => ['required', 'integer', 'min:0'],
            'search.meta.totalPages' => ['required', 'integer', 'min:0'],
            'search.facets' => ['present', 'array', 'list', 'max:30'],
            'search.facets.*' => ['required', 'array:key,label,type,unit,values'],
            'search.facets.*.key' => ['required', 'string', 'max:80'],
            'search.facets.*.label' => ['required', 'string', 'max:120'],
            'search.facets.*.type' => ['required', 'string', Rule::in(['string', 'integer', 'decimal', 'boolean', 'date', 'enum'])],
            'search.facets.*.unit' => ['nullable', 'string', 'max:16'],
            'search.facets.*.values' => ['present', 'array', 'list', 'max:50'],
            'search.facets.*.values.*' => ['required', 'array:value,count'],
            'search.facets.*.values.*.value' => ['required', 'string', 'max:80'],
            'search.facets.*.values.*.count' => ['required', 'integer', 'min:0'],
            'search.categories' => ['present', 'array', 'list', 'max:100'],
            'search.categories.*' => ['required', 'array:path,title'],
            'search.categories.*.path' => ['required', 'string', 'max:200'],
            'search.categories.*.title' => ['required', 'string', 'max:200'],
            'search.sorts' => ['present', 'array', 'list', 'max:40'],
            'search.sorts.*' => ['required', 'array:value,label'],
            'search.sorts.*.value' => ['required', 'string', 'max:80'],
            'search.sorts.*.label' => ['required', 'string', 'max:120'],
        ])->validate()['search'];
        $products = [];
        foreach ($data['products'] as $product) {
            $amount = (int) $product['money']['amountMinor'];
            $exponent = (int) $product['money']['exponent'];
            $scale = 10 ** $exponent;
            $fraction = $exponent === 0 ? '' : '.'.str_pad((string) ($amount % $scale), $exponent, '0', STR_PAD_LEFT);
            $products[] = [
                'id' => $product['id'],
                'url' => $product['url'],
                'title' => $product['title'],
                'available' => (bool) $product['available'],
                'availability' => $product['availability'],
                'image' => $product['image'],
                'money' => [
                    'amountMinor' => $amount,
                    'currency' => $product['money']['currency'],
                    'exponent' => $exponent,
                    'formatted' => $product['money']['currency'].' '.intdiv($amount, $scale).$fraction,
                ],
            ];
        }

        return new self($data['query'], $products, $data['meta'], $data['facets'], $data['categories'], $data['sorts']);
    }

    /** @param  array<string, mixed>  $overrides */
    public function queryString(array $overrides = []): string
    {
        $query = array_replace($this->query, $overrides);
        $params = [
            'q' => is_string($query['q'] ?? null) ? $query['q'] : '',
            'category' => is_string($query['category'] ?? null) ? $query['category'] : '',
            'price_min' => $query['price_min'] ?? null,
            'price_max' => $query['price_max'] ?? null,
            'available' => is_string($query['available'] ?? null) ? $query['available'] : '',
            'sort' => is_string($query['sort'] ?? null) ? $query['sort'] : 'title',
            'page' => (int) ($query['page'] ?? 1),
            'page_size' => (int) ($query['page_size'] ?? 24),
        ];
        $facets = is_array($query['facets'] ?? null) ? $query['facets'] : [];
        if ($facets !== []) {
            $params['f'] = $facets;
        }
        $filtered = [];
        foreach ($params as $key => $value) {
            if ($key === 'q' && $value === '') {
                continue;
            }
            if ($key === 'category' && $value === '') {
                continue;
            }
            if (($key === 'price_min' || $key === 'price_max') && $value === null) {
                continue;
            }
            if ($key === 'available' && $value === '') {
                continue;
            }
            if ($key === 'sort' && $value === 'title') {
                continue;
            }
            if ($key === 'page_size' && $value === 24) {
                continue;
            }
            $filtered[$key] = $value;
        }

        return http_build_query($filtered);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'query' => $this->query,
            'products' => $this->products,
            'meta' => $this->meta,
            'facets' => $this->facets,
            'categories' => $this->categories,
            'sorts' => $this->sorts,
        ];
    }
}
