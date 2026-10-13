<?php

namespace Database\Seeders;

use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\Catalog\Support\CatalogMediaUploadAuthorization;
use App\Domain\CheckoutOrders\Actions\ReadCart;
use App\Domain\CheckoutOrders\Actions\UpdateCart;
use App\Domain\CheckoutOrders\Support\CartOwner;
use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Platform\Actions\ProcessMedia;
use App\Domain\Platform\Actions\StoreMedia;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoStoreSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo catalog fixtures are refused in production.');
        }
        $owner = StaffAccount::query()->orderBy('created_at')->first();
        if (! $owner instanceof StaffAccount) {
            throw new RuntimeException('Install a store owner before seeding demo catalog products.');
        }
        if (! DB::table('products')->where('slug', 'studio-lamp')->exists()) {
            app(SaveProduct::class)->save($owner, [
                'title' => 'Studio Lamp',
                'slug' => 'studio-lamp',
                'description' => 'A fictional physical lamp for local catalog demos.',
                'status' => 'published',
                'type' => 'physical',
                'sku' => 'LAMP-001',
                'price_amount' => 2499,
                'image_asset_id' => $this->readyImage($owner),
                'image_alt' => 'Brushed steel studio lamp',
            ]);
        }
        if (! DB::table('products')->where('slug', 'studio-guide')->exists()) {
            $digital = app(SaveProduct::class)->save($owner, [
                'title' => 'Studio Lighting Guide',
                'slug' => 'studio-guide',
                'description' => 'A fictional downloadable lighting guide.',
                'status' => 'published',
                'type' => 'digital',
                'sku' => 'GUIDE-001',
                'price_amount' => 899,
                'image_asset_id' => $this->readyImage($owner),
                'image_alt' => 'Cover of the studio lighting guide',
            ]);
            $versioned = app(VersionDigitalAsset::class)->version($owner, [
                'product_id' => $digital['id'],
                'label' => 'Lighting guide',
                'file' => UploadedFile::fake()->createWithContent('studio-guide.txt', "Fictional studio lighting guide.\n"),
            ]);
            $variantId = (string) DB::table('product_variants')->where('product_id', $digital['id'])->value('id');
            app(VersionDigitalAsset::class)->attach($owner, [
                'product_id' => $digital['id'],
                'variant_id' => $variantId,
                'version_ids' => [$versioned['id']],
            ]);
        }
        $this->mixedCart();
    }

    private function mixedCart(): void
    {
        $customer = CustomerAccount::query()->where('email', 'demo-shopper@example.test')->first();
        if (! $customer instanceof CustomerAccount) {
            $customer = app(RegisterCustomer::class)->register([
                'name' => 'Fictional Demo Shopper',
                'email' => 'demo-shopper@example.test',
                'password' => 'Fictional demo shopper passphrase 471!',
                'password_confirmation' => 'Fictional demo shopper passphrase 471!',
            ]);
        }
        $owner = CartOwner::customer((string) $customer->id);
        $cart = app(ReadCart::class)->snapshot($owner);
        if ($cart['lines'] !== []) {
            return;
        }
        $physical = (string) DB::table('product_variants')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->where('products.slug', 'studio-lamp')
            ->value('product_variants.id');
        $digital = (string) DB::table('product_variants')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->where('products.slug', 'studio-guide')
            ->value('product_variants.id');
        $cart = app(UpdateCart::class)->replaceLine($owner, [
            'variant_id' => $physical,
            'quantity' => 1,
            'expected_revision' => $cart['revision'],
        ]);
        app(UpdateCart::class)->replaceLine($owner, [
            'variant_id' => $digital,
            'quantity' => 1,
            'expected_revision' => $cart['revision'],
        ]);
    }

    private function readyImage(StaffAccount $owner): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        if (! is_string($png) || $png === '') {
            throw new RuntimeException('Demo image fixture could not be decoded.');
        }
        $path = sys_get_temp_dir().'/mrktflow-demo-'.uniqid('', true).'.png';
        file_put_contents($path, $png);
        $file = new UploadedFile($path, 'demo.png', 'image/png', null, true);
        $id = app(StoreMedia::class)->store($file, 'public_image', new CatalogMediaUploadAuthorization($owner));
        app(ProcessMedia::class)->process($id);
        @unlink($path);

        return $id;
    }
}
