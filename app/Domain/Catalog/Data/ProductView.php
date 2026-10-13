<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use App\Domain\Catalog\Support\VariantAvailability;
use App\Domain\Platform\Support\SetupChoices;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonSerializable;

final readonly class ProductView implements JsonSerializable
{
    /**
     * @param  array{url: string, alt: string}  $image
     * @param  list<array{title: string, url: string}>  $breadcrumbs
     * @param  list<array{name: string, values: list<string>}>  $optionAxes
     * @param  list<array{label: string, value: string}>  $typedAttributes
     * @param  list<array{id: string, sku: string, label: string, isDefault: bool, available: bool, availability: string, options: list<array{name: string, value: string}>, money: array{amount: int, currency: string, exponent: int}, image: array{url: string, alt: string}, max_quantity?: int, digital_files?: list<array{label: string, version: int, bytes: int, content_type: string}>}>  $variants
     */
    private function __construct(
        public string $id,
        public string $slug,
        public string $url,
        public string $title,
        public string $description,
        public string $type,
        public bool $available,
        public string $availability,
        public array $image,
        public array $breadcrumbs,
        public array $optionAxes,
        public array $typedAttributes,
        public array $variants,
    ) {}

    /** @param array<string, mixed> $snapshot */
    public static function fromPublic(array $snapshot): self
    {
        $path = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('#^/(?!/)[A-Za-z0-9/_.-]*$#D', $value) || array_intersect(explode('/', $value), ['.', '..']) !== []) {
                $fail('Use a local root-relative path without traversal or URL parameters.');
            }
        };
        $data = Validator::make(['product' => $snapshot], [
            'product' => ['required', 'array'],
            'product.id' => ['required', 'uuid'],
            'product.slug' => ['required', 'string', 'max:80'],
            'product.title' => ['required', 'string', 'max:200'],
            'product.description' => ['required', 'string', 'max:5000'],
            'product.type' => ['required', 'string', Rule::in(['physical', 'digital'])],
            'product.available' => ['required', 'boolean'],
            'product.availability' => ['required', 'string', Rule::in([VariantAvailability::IN_STOCK, VariantAvailability::OUT_OF_STOCK, VariantAvailability::UNAVAILABLE])],
            'product.image' => ['required', 'array:url,alt'],
            'product.image.url' => ['required', 'string', 'max:500', $path],
            'product.image.alt' => ['required', 'string', 'max:200'],
            'product.breadcrumbs' => ['present', 'array', 'list', 'max:5'],
            'product.breadcrumbs.*' => ['required', 'array:title,url'],
            'product.breadcrumbs.*.title' => ['required', 'string', 'max:200'],
            'product.breadcrumbs.*.url' => ['required', 'string', 'max:500', $path],
            'product.option_axes' => ['present', 'array', 'list', 'max:3'],
            'product.option_axes.*' => ['required', 'array:name,values'],
            'product.option_axes.*.name' => ['required', 'string', 'max:40'],
            'product.option_axes.*.values' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'product.option_axes.*.values.*' => ['required', 'string', 'max:40'],
            'product.typed_attributes' => ['present', 'array', 'list', 'max:30'],
            'product.typed_attributes.*' => ['required', 'array:label,value'],
            'product.typed_attributes.*.label' => ['required', 'string', 'max:120'],
            'product.typed_attributes.*.value' => ['required', 'string', 'max:500'],
            'product.variants' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'product.variants.*' => ['required', 'array'],
            'product.variants.*.id' => ['required', 'uuid', 'distinct:strict'],
            'product.variants.*.sku' => ['required', 'string', 'max:64'],
            'product.variants.*.label' => ['required', 'string', 'max:120'],
            'product.variants.*.isDefault' => ['required', 'boolean'],
            'product.variants.*.available' => ['required', 'boolean'],
            'product.variants.*.availability' => ['required', 'string', Rule::in([VariantAvailability::IN_STOCK, VariantAvailability::OUT_OF_STOCK, VariantAvailability::UNAVAILABLE])],
            'product.variants.*.options' => ['present', 'array', 'list', 'max:3'],
            'product.variants.*.options.*' => ['required', 'array:name,value'],
            'product.variants.*.options.*.name' => ['required', 'string', 'max:40'],
            'product.variants.*.options.*.value' => ['required', 'string', 'max:40'],
            'product.variants.*.money' => ['required', 'array:amount,currency,exponent'],
            'product.variants.*.money.amount' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'product.variants.*.money.currency' => ['required', 'string', Rule::in(array_column(app(SetupChoices::class)->currencies(), 'code'))],
            'product.variants.*.money.exponent' => ['required', 'integer', 'min:0', 'max:3'],
            'product.variants.*.image' => ['required', 'array:url,alt'],
            'product.variants.*.image.url' => ['required', 'string', 'max:500', $path],
            'product.variants.*.image.alt' => ['required', 'string', 'max:200'],
            'product.variants.*.max_quantity' => ['sometimes', 'integer', 'min:1', 'max:1'],
            'product.variants.*.digital_files' => ['sometimes', 'array', 'list', 'max:20'],
            'product.variants.*.digital_files.*' => ['required', 'array:label,version,bytes,content_type'],
            'product.variants.*.digital_files.*.label' => ['required', 'string', 'max:120'],
            'product.variants.*.digital_files.*.version' => ['required', 'integer', 'min:1'],
            'product.variants.*.digital_files.*.bytes' => ['required', 'integer', 'min:0'],
            'product.variants.*.digital_files.*.content_type' => ['required', 'string', 'max:120'],
        ])->validate()['product'];
        $variants = [];
        foreach ($data['variants'] as $variant) {
            $entry = [
                'id' => $variant['id'],
                'sku' => $variant['sku'],
                'label' => $variant['label'],
                'isDefault' => (bool) $variant['isDefault'],
                'available' => (bool) $variant['available'],
                'availability' => $variant['availability'],
                'options' => $variant['options'],
                'money' => [
                    'amount' => (int) $variant['money']['amount'],
                    'currency' => $variant['money']['currency'],
                    'exponent' => (int) $variant['money']['exponent'],
                ],
                'image' => $variant['image'],
            ];
            if (array_key_exists('max_quantity', $variant)) {
                $entry['max_quantity'] = (int) $variant['max_quantity'];
            }
            if (array_key_exists('digital_files', $variant)) {
                $entry['digital_files'] = $variant['digital_files'];
            }
            $variants[] = $entry;
        }

        return new self(
            $data['id'],
            $data['slug'],
            '/products/'.$data['slug'],
            $data['title'],
            $data['description'],
            $data['type'],
            (bool) $data['available'],
            $data['availability'],
            $data['image'],
            $data['breadcrumbs'],
            $data['option_axes'],
            $data['typed_attributes'],
            $variants,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'available' => $this->available,
            'availability' => $this->availability,
            'image' => $this->image,
            'breadcrumbs' => $this->breadcrumbs,
            'option_axes' => $this->optionAxes,
            'typed_attributes' => $this->typedAttributes,
            'variants' => $this->variants,
        ];
    }
}
