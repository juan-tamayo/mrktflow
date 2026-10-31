<?php

namespace Database\Seeders;

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveVariants;
use App\Domain\Catalog\Support\CatalogMediaUploadAuthorization;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\ProcessMedia;
use App\Domain\Platform\Actions\StoreMedia;
use App\Domain\Pricing\Actions\SaveCoupon;
use App\Domain\Pricing\Actions\SaveTaxRules;
use App\Domain\Shipping\Actions\SaveShippingRules;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Casa Norte: the fictional Tijuana pantry that demos MRKTFlow end to end.
 * Product photographs are openly licensed pictures of each product's world;
 * credits live in database/seeders/images/credits.json.
 */
class DemoTiendaSeeder extends Seeder
{
    /** @var array<string, string> */
    private array $images = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo store fixtures are refused in production.');
        }
        $owner = StaffAccount::query()->orderBy('created_at')->first();
        if (! $owner instanceof StaffAccount) {
            throw new RuntimeException('Install a store owner before seeding the demo store.');
        }

        $categories = $this->categories($owner);
        $this->products($owner, $categories);
        $this->shipping($owner);
        $this->tax($owner);
        $this->coupon($owner);
    }

    /** @return array<string, string> slug => category id */
    private function categories(StaffAccount $owner): array
    {
        $definitions = [
            'salsas-y-pastas' => ['Salsas y pastas', 'Salsas de taller, moles y lo que pica rico.'],
            'cafe-y-dulces' => ['Café y dulces', 'Café de altura, chocolate de metate y dulces de antes.'],
            'despensa' => ['Despensa y hogar', 'Lo básico que nunca sobra en la cocina.'],
        ];
        $ids = [];
        foreach ($definitions as $slug => [$title, $description]) {
            $existing = DB::table('categories')->where('slug', $slug)->value('id');
            if ($existing !== null) {
                $ids[$slug] = (string) $existing;

                continue;
            }
            $saved = app(SaveCategory::class)->save($owner, [
                'title' => $title,
                'slug' => $slug,
                'description' => $description,
                'status' => 'published',
                'parent_id' => null,
            ]);
            $ids[$slug] = $saved['id'];
        }

        return $ids;
    }

    /** @param array<string, string> $categories */
    private function products(StaffAccount $owner, array $categories): void
    {
        $definitions = [
            ['Salsa macha de chile de árbol', 'salsa-macha', 'Aceite de chiles de árbol y cascabel con ajo dorado y cacahuate. La que va bien con todo: huevos, tacos, ramen, lo que haya.', 9500, 'CN-MACHA', 'driedchilesmerced.jpg', 'Chiles secos en un puesto de mercado', 'salsas-y-pastas', 420],
            ['Salsa roja tatemada', 'salsa-roja-tatemada', 'Jitomate, cebolla y chile serrano tatemados en comal, molidos como en casa. Pica con respeto.', 8500, 'CN-ROJA', 'salsa-roja-lista.jpg', 'Salsa roja recién hecha', 'salsas-y-pastas', 480],
            ['Mole negro oaxaqueño 500 g', 'mole-negro', 'Pasta de mole negro con más de veinte ingredientes, lista para desleír en caldo. El de los domingos.', 18000, 'CN-MOLE', 'mole-casero-picante-mexico.jpg', 'Mole casero servido en plato', 'salsas-y-pastas', 560],
            ['Chocolate de metate 300 g', 'chocolate-de-metate', 'Cacao molido en metate con canela y un toque de piloncillo. Para chocolate caliente espeso, con su espuma.', 12000, 'CN-CHOC', 'cacao-in-jar.jpg', 'Cacao en un frasco de vidrio', 'cafe-y-dulces', 340],
            ['Cajeta artesanal 350 g', 'cajeta-artesanal', 'Leche cocida a fuego lento hasta que toma color y paciencia. Para el pan, la nieve o a cucharadas.', 11000, 'CN-CAJETA', 'dulcedeleche.jpg', 'Frasco de cajeta de leche', 'cafe-y-dulces', 410],
            ['Ate de guayaba 400 g', 'ate-de-guayaba', 'Guayaba de temporada cocida despacio hasta quedar firme y dulce. Con queso fresco es otra cosa.', 7500, 'CN-ATE', 'goiaba-vermelha.jpg', 'Guayabas rojas maduras', 'cafe-y-dulces', 430],
            ['Miel de agave 350 ml', 'miel-de-agave', 'Endulzante de agave, suave y sin empalagar. Para el café, la fruta o el té de la noche.', 15000, 'CN-MIEL', 'runny-hunny.jpg', 'Miel dorada cayendo de una cuchara', 'despensa', 480],
            ['Sal de mar de Colima 500 g', 'sal-de-mar', 'Sal de mar en grano, cosechada en salinas del Pacífico. La de acabarse el platillo.', 6000, 'CN-SAL', 'seasalt.jpg', 'Sal de mar en grano', 'despensa', 520],
            ['Piloncillo artesanal 500 g', 'piloncillo', 'Conos de piloncillo para el café de olla, los atoles y los postres de antes.', 5500, 'CN-PILON', 'panela-or-piloncillo.jpg', 'Conos de piloncillo', 'despensa', 540],
            ['Tote de yute «Hecho en Tijuana»', 'tote-de-yute', 'La bolsa del mercado de la casa: yute resistente, serigrafía local y espacio para toda la despensa.', 19900, 'CN-TOTE', 'puesto-de-bolsas-de-yute-cliza-cochabamba.png', 'Puesto de bolsas de yute en un mercado', 'despensa', 180],
        ];
        foreach ($definitions as [$title, $slug, $description, $price, $sku, $image, $alt, $category, $grams]) {
            if (DB::table('products')->where('slug', $slug)->exists()) {
                continue;
            }
            $saved = app(SaveProduct::class)->save($owner, [
                'title' => $title,
                'slug' => $slug,
                'description' => $description,
                'status' => 'published',
                'type' => 'physical',
                'sku' => $sku,
                'price_amount' => $price,
                'image_asset_id' => $this->image($owner, $image),
                'image_alt' => $alt,
                'category_ids' => [$categories[$category]],
            ]);
            $variantId = (string) DB::table('product_variants')->where('product_id', $saved['id'])->value('id');
            $this->stock($owner, $variantId, 24);
        }

        $this->cafe($owner, $categories['cafe-y-dulces']);
    }

    private function cafe(StaffAccount $owner, string $categoryId): void
    {
        $existing = DB::table('products')->where('slug', 'cafe-de-altura')->first();
        if ($existing !== null) {
            $hasMatrix = DB::table('product_variants')->where('product_id', $existing->id)
                ->get(['options'])->contains(fn (object $variant): bool => json_decode((string) $variant->options, true) !== [] && json_decode((string) $variant->options, true) !== null);
            if ($hasMatrix) {
                return;
            }
            // Product was created but the variant matrix was not (an earlier
            // seed run stopped between the two saves): apply it now.
            $whole = (string) DB::table('product_media')->where('product_id', $existing->id)->orderBy('sort_order')->value('media_asset_id');
            $ground = $this->image($owner, 'coarsely-grounded-coffee-beans-in-white-bowl.png');
            $this->cafeVariants($owner, (string) $existing->id, (int) $existing->version, $whole, $ground);

            return;
        }
        $whole = $this->image($owner, 'roasted-coffee-beans-in-white-bowl.png');
        $ground = $this->image($owner, 'coarsely-grounded-coffee-beans-in-white-bowl.png');
        $saved = app(SaveProduct::class)->save($owner, [
            'title' => 'Café de altura 500 g',
            'slug' => 'cafe-de-altura',
            'description' => 'Café de altura en grano o molido, tostado en lotes pequeños. Notas a chocolate y panela; el de todas las mañanas en la casa.',
            'status' => 'published',
            'type' => 'physical',
            'sku' => 'CN-CAFE',
            'price_amount' => 24000,
            'image_asset_id' => $whole,
            'image_alt' => 'Granos de café tostado en un tazón blanco',
            'category_ids' => [$categoryId],
        ]);
        $this->cafeVariants($owner, (string) $saved['id'], (int) $saved['version'], $whole, $ground);
    }

    private function cafeVariants(StaffAccount $owner, string $productId, int $version, string $whole, string $ground): void
    {
        app(SaveVariants::class)->save($owner, [
            'id' => $productId,
            'version' => $version,
            'options' => [['name' => 'Molienda', 'values' => ['Grano entero', 'Molido']]],
            'variants' => [
                [
                    'sku' => 'CN-CAFE-GRANO', 'price_amount' => 24000, 'is_default' => true, 'available' => true,
                    'options' => ['Molienda' => 'Grano entero'],
                    'image_asset_id' => $whole, 'image_alt' => 'Granos de café tostado en un tazón blanco',
                    'weight_grams' => 540, 'length_mm' => 220, 'width_mm' => 120, 'height_mm' => 80,
                ],
                [
                    'sku' => 'CN-CAFE-MOLIDO', 'price_amount' => 24000, 'is_default' => false, 'available' => true,
                    'options' => ['Molienda' => 'Molido'],
                    'image_asset_id' => $ground, 'image_alt' => 'Café molido en un tazón blanco',
                    'weight_grams' => 540, 'length_mm' => 220, 'width_mm' => 120, 'height_mm' => 80,
                ],
            ],
        ]);
        $variantIds = DB::table('product_variants')->where('product_id', $productId)->pluck('id');
        foreach ($variantIds as $variantId) {
            $this->stock($owner, (string) $variantId, 18);
        }
    }

    private function stock(StaffAccount $owner, string $variantId, int $onHand): void
    {
        app(AdjustStock::class)->adjust($owner, [
            'variant_id' => $variantId,
            'operation_key' => (string) Str::uuid(),
            'tracks_inventory' => true,
            'on_hand' => $onHand,
            'low_stock_threshold' => 4,
        ]);
    }

    private function shipping(StaffAccount $owner): void
    {
        if (DB::table('shipping_zones')->exists() && DB::table('shipping_zones')->count() > 0) {
            return;
        }
        app(SaveShippingRules::class)->save($owner, [
            'origin' => [
                'country_code' => 'MX',
                'region_code' => 'BC',
                'postal_code' => '22000',
                'city' => 'Tijuana',
                'street' => 'Calle Ficticia 123, Zona Centro',
            ],
            'classes' => [['key' => 'general', 'name' => 'General']],
            'zones' => [
                [
                    'name' => 'México',
                    'priority' => 20,
                    'status' => 'active',
                    'matches' => [['country_code' => 'MX', 'region_code' => null, 'postal_pattern' => null]],
                    'methods' => [[
                        'label' => 'Envío nacional', 'type' => 'flat', 'amount' => 9900,
                        'free_min_subtotal' => 99900, 'table_basis' => null, 'table_rows' => [],
                        'included_class_keys' => [], 'excluded_class_keys' => [], 'pickup' => null,
                    ]],
                ],
                [
                    'name' => 'Estados Unidos',
                    'priority' => 10,
                    'status' => 'active',
                    'matches' => [['country_code' => 'US', 'region_code' => null, 'postal_pattern' => null]],
                    'methods' => [[
                        'label' => 'Envío a EE. UU.', 'type' => 'flat', 'amount' => 34900,
                        'free_min_subtotal' => null, 'table_basis' => null, 'table_rows' => [],
                        'included_class_keys' => [], 'excluded_class_keys' => [], 'pickup' => null,
                    ]],
                ],
            ],
        ]);
    }

    private function tax(StaffAccount $owner): void
    {
        if (DB::table('tax_rules')->exists() && DB::table('tax_rules')->count() > 0) {
            return;
        }
        app(SaveTaxRules::class)->save($owner, [
            'price_mode' => 'inclusive',
            'shipping_tax_class_key' => 'standard',
            'version' => null,
            'classes' => [['key' => 'standard', 'name' => 'Estándar']],
            'rules' => [[
                'name' => 'IVA México',
                'class_key' => 'standard',
                'status' => 'active',
                'rate_numerator' => 16,
                'rate_denominator' => 100,
                'match' => ['country_code' => 'MX', 'region_code' => null, 'postal_pattern' => null],
            ]],
        ]);
    }

    private function coupon(StaffAccount $owner): void
    {
        if (DB::table('coupons')->where('code_normalized', 'NORTE10')->exists()) {
            return;
        }
        app(SaveCoupon::class)->save($owner, [
            'code' => 'NORTE10',
            'name' => 'Bienvenida Casa Norte',
            'mode' => 'percentage',
            'percent_numerator' => 10,
            'percent_denominator' => 100,
            'amount' => null,
            'min_eligible_subtotal' => 0,
            'starts_at' => '2026-01-01T00:00:00Z',
            'ends_at' => '2027-12-31T00:00:00Z',
            'max_redemptions' => null,
            // Null per-customer cap on purpose: the pricing engine refuses to
            // hold a per-customer-capped coupon for a guest checkout (there is
            // no customer key to count against), and the demo is guest-first.
            'max_redemptions_per_customer' => null,
            'status' => 'active',
            'product_ids' => [],
            'category_ids' => [],
        ]);
    }

    private function image(StaffAccount $owner, string $filename): string
    {
        if (isset($this->images[$filename])) {
            return $this->images[$filename];
        }
        $source = __DIR__.'/images/'.$filename;
        if (! is_file($source)) {
            throw new RuntimeException('Demo image is missing: '.$filename);
        }
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $path = sys_get_temp_dir().'/mrktflow-tienda-'.uniqid('', true).'.'.$extension;
        copy($source, $path);
        $mime = $extension === 'png' ? 'image/png' : 'image/jpeg';
        $file = new UploadedFile($path, $filename, $mime, null, true);
        $id = app(StoreMedia::class)->store($file, 'public_image', new CatalogMediaUploadAuthorization($owner));
        app(ProcessMedia::class)->process($id);
        @unlink($path);

        return $this->images[$filename] = $id;
    }
}
