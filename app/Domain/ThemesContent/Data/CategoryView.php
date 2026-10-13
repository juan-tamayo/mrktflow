<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Data;

use App\Domain\Platform\Support\SetupChoices;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonSerializable;

final readonly class CategoryView implements JsonSerializable
{
    /**
     * @param  list<array{title: string, url: string}>  $breadcrumbs
     * @param  list<array{id: string, url: string, title: string, available: bool, availability: string, image: array{url: string, alt: string}, money: array{amountMinor: int, currency: string, exponent: int, formatted: string}}>  $products
     */
    private function __construct(
        public string $id,
        public string $url,
        public string $title,
        public string $description,
        public array $breadcrumbs,
        public array $products,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $path = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('#^/(?!/)[A-Za-z0-9/_.-]*$#D', $value) || array_intersect(explode('/', $value), ['.', '..']) !== []) {
                $fail('Use a local root-relative path without traversal or URL parameters.');
            }
        };
        $data = Validator::make(['category' => $input], [
            'category' => ['required', 'array:id,url,title,description,breadcrumbs,products'],
            'category.id' => ['required', 'uuid'],
            'category.url' => ['required', 'string', 'max:500', $path],
            'category.title' => ['required', 'string', 'max:200'],
            'category.description' => ['required', 'string', 'max:5000'],
            'category.breadcrumbs' => ['present', 'array', 'list', 'max:5'],
            'category.breadcrumbs.*' => ['required', 'array:title,url'],
            'category.breadcrumbs.*.title' => ['required', 'string', 'max:200'],
            'category.breadcrumbs.*.url' => ['required', 'string', 'max:500', $path],
            'category.products' => ['present', 'array', 'list', 'max:24'],
            'category.products.*' => ['required', 'array:id,url,title,image,money,available,availability'],
            'category.products.*.id' => ['required', 'uuid', 'distinct:strict'],
            'category.products.*.url' => ['required', 'string', 'max:500', $path],
            'category.products.*.title' => ['required', 'string', 'max:200'],
            'category.products.*.available' => ['required', 'boolean'],
            'category.products.*.availability' => ['required', 'string', Rule::in(['in_stock', 'out_of_stock', 'unavailable'])],
            'category.products.*.image' => ['required', 'array:url,alt'],
            'category.products.*.image.url' => ['required', 'string', 'max:500', $path],
            'category.products.*.image.alt' => ['required', 'string', 'max:200'],
            'category.products.*.money' => ['required', 'array:amountMinor,currency,exponent'],
            'category.products.*.money.amountMinor' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'category.products.*.money.currency' => ['required', 'string', Rule::in(array_column(app(SetupChoices::class)->currencies(), 'code'))],
            'category.products.*.money.exponent' => ['required', 'integer', 'min:0', 'max:3'],
        ])->validate()['category'];
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

        return new self($data['id'], $data['url'], $data['title'], $data['description'], $data['breadcrumbs'], $products);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'breadcrumbs' => $this->breadcrumbs,
            'products' => $this->products,
        ];
    }
}
