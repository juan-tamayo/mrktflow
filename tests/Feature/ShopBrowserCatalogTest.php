<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Catalog\Actions\SaveCategory;
use App\Domain\Catalog\Actions\SaveProduct;
use Database\Seeders\BrowserShopCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local', ['throw' => true]);
    Storage::fake('public', ['throw' => true]);
});

it('republishes the archived studio lamp and leaves comparison products on catalog routes', function (): void {
    $owner = catalogInstalledOwner();
    $image = catalogReadyImage($owner->id);
    $category = app(SaveCategory::class)->save($owner, catalogCategoryInput(['status' => 'published']));
    $lamp = app(SaveProduct::class)->save($owner, catalogProductInput($image, [
        'status' => 'published',
        'category_ids' => [$category['id']],
    ]));
    app(ArchiveProduct::class)->archive($owner, ['id' => $lamp['id'], 'version' => $lamp['version']]);

    $this->get('/products/studio-lamp')->assertNotFound();
    $this->get('/products/alpha-camera')->assertNotFound();

    app(BrowserShopCatalogSeeder::class)->run();

    $this->get('/')->assertStatus(503)
        ->assertSee('This store is getting ready')
        ->assertDontSee('Studio Lamp')
        ->assertDontSee('Alpha Camera');
    $this->get('/products/studio-lamp')->assertOk()->assertSee('Studio Lamp');
    $this->get('/products/alpha-camera')->assertOk()->assertSee('Alpha Camera');
    $this->get('/products/beta-camera')->assertOk()->assertSee('Beta Camera');
    $this->get('/products/pro-camera')->assertOk()->assertSee('Pro Camera');
    $this->get('/products/gamma-camera')->assertOk()->assertSee('Gamma Camera');
    $this->get('/products/field-notebook')->assertOk()->assertSee('Field Notebook');
    expect(DB::table('coupons')->where('code_normalized', 'FREE100')->where('status', 'active')->exists())->toBeTrue();
});
