<?php

declare(strict_types=1);

namespace App\Domain\ThemesContent\Data;

use App\Domain\Platform\Support\SetupChoices;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonSerializable;

final readonly class ProductView implements JsonSerializable
{
    /**
     * @param  array{url: string, alt: string}  $image
     * @param  list<array{id: string, label: string, money: array{amountMinor: int, currency: string, exponent: int, formatted: string}}>  $variants
     * @param  list<array{label: string, value: string}>  $attributes
     */
    private function __construct(public string $id, public string $url, public string $title, public string $description, public array $image, public array $variants, public array $attributes) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input): self
    {
        $path = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('#^/(?!/)[A-Za-z0-9/_.-]*$#D', $value) || array_intersect(explode('/', $value), ['.', '..']) !== []) {
                $fail('Use a local root-relative path without traversal or URL parameters.');
            }
        };
        $data = Validator::make(['product' => $input], [
            'product' => ['required', 'array:id,url,title,description,image,variants,attributes'],
            'product.id' => ['required', 'uuid'],
            'product.url' => ['required', 'string', 'max:500', $path],
            'product.title' => ['required', 'string', 'max:200'],
            'product.description' => ['required', 'string', 'max:5000'],
            'product.image' => ['required', 'array:url,alt'],
            'product.image.url' => ['required', 'string', 'max:500', $path],
            'product.image.alt' => ['required', 'string', 'max:200'],
            'product.variants' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'product.variants.*' => ['required', 'array:id,label,money'],
            'product.variants.*.id' => ['required', 'uuid', 'distinct:strict'],
            'product.variants.*.label' => ['required', 'string', 'max:120'],
            'product.variants.*.money' => ['required', 'array:amountMinor,currency,exponent'],
            'product.variants.*.money.amountMinor' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'product.variants.*.money.currency' => ['required', 'string', Rule::in(array_column(app(SetupChoices::class)->currencies(), 'code'))],
            'product.variants.*.money.exponent' => ['required', 'integer', 'min:0', 'max:3'],
            'product.attributes' => ['present', 'array', 'list', 'max:30'],
            'product.attributes.*' => ['required', 'array:label,value'],
            'product.attributes.*.label' => ['required', 'string', 'max:120'],
            'product.attributes.*.value' => ['required', 'string', 'max:500'],
        ])->validate()['product'];
        $variants = [];
        foreach ($data['variants'] as $variant) {
            $amount = (int) $variant['money']['amountMinor'];
            $exponent = (int) $variant['money']['exponent'];
            $scale = 10 ** $exponent;
            $fraction = $exponent === 0 ? '' : '.'.str_pad((string) ($amount % $scale), $exponent, '0', STR_PAD_LEFT);
            $variants[] = ['id' => $variant['id'], 'label' => $variant['label'], 'money' => [
                'amountMinor' => $amount, 'currency' => $variant['money']['currency'], 'exponent' => $exponent,
                'formatted' => $variant['money']['currency'].' '.intdiv($amount, $scale).$fraction,
            ]];
        }

        return new self($data['id'], $data['url'], $data['title'], $data['description'], $data['image'], $variants, $data['attributes']);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'url' => $this->url, 'title' => $this->title, 'description' => $this->description, 'image' => $this->image, 'variants' => $this->variants, 'attributes' => $this->attributes];
    }
}
