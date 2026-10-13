<?php

declare(strict_types=1);

use App\Application\Setup\CreateInitialOwner;
use App\Domain\Identity\Actions\ManageTwoFactor;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\Identity\Support\StaffTotp;
use App\Domain\Platform\Actions\InstallStore;
use App\Domain\Platform\Actions\ProcessMedia;
use App\Domain\Platform\Actions\SetupAccess;
use App\Domain\Platform\Actions\StoreMedia;
use App\Domain\Platform\Contracts\MediaUploadAuthorization;
use Illuminate\Http\UploadedFile;

function catalogOwnerInput(): array
{
    return ['name' => 'Example Owner', 'email' => 'owner@example.test', 'password' => 'Fictional owner passphrase 471!', 'password_confirmation' => 'Fictional owner passphrase 471!'];
}

/** @return array<string, mixed> */
function catalogStaffSession(StaffAccount $staff): array
{
    $session = ['staff_session_version' => $staff->session_version];
    if (! StaffPermissions::requiresTwoFactor($staff)) {
        return $session;
    }
    if (! app(ManageTwoFactor::class)->confirmed($staff)) {
        $enrollment = app(ManageTwoFactor::class)->start($staff);
        app(ManageTwoFactor::class)->confirm($staff, StaffTotp::code($enrollment->secret));
    }
    $session['staff_two_factor_verified'] = true;
    $session['staff_reauthenticated_at'] = now()->getTimestamp();

    return $session;
}

function catalogInstalledOwner(): StaffAccount
{
    $access = app(SetupAccess::class);
    $grant = $access->unlock($access->issueSecret());
    app(InstallStore::class)->save($grant, json_decode(file_get_contents(base_path('tests/Fixtures/store-settings.json')), true, flags: JSON_THROW_ON_ERROR), 0);

    return app(CreateInitialOwner::class)->create($grant, catalogOwnerInput());
}

function catalogReadyImage(string $staffId): string
{
    $id = app(StoreMedia::class)->store(UploadedFile::fake()->image('photo.png', 32, 24), 'public_image', new class($staffId) implements MediaUploadAuthorization
    {
        public function __construct(private readonly string $ownerId) {}

        public function authorizeUpload(): string
        {
            return $this->ownerId;
        }
    });
    app(ProcessMedia::class)->process($id);

    return $id;
}

/** @param array<string, mixed> $overrides */
function catalogProductInput(string $imageId, array $overrides = []): array
{
    return array_replace([
        'title' => 'Studio Lamp',
        'slug' => 'studio-lamp',
        'description' => 'A simple physical lamp for the workbench.',
        'status' => 'draft',
        'sku' => 'LAMP-001',
        'price_amount' => 2499,
        'image_asset_id' => $imageId,
        'image_alt' => 'Brushed steel studio lamp',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function catalogCategoryInput(array $overrides = []): array
{
    return array_replace([
        'title' => 'Lighting',
        'slug' => 'lighting',
        'description' => 'Lamps and fixtures for the studio.',
        'status' => 'draft',
        'parent_id' => null,
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function catalogSchemaInput(array $overrides = []): array
{
    $attributes = $overrides['attributes'] ?? [
        [
            'key' => 'sensor.megapixels', 'type' => 'decimal', 'unit' => 'MP', 'required' => true,
            'comparable' => true, 'filterable' => true, 'label' => 'Megapixels',
        ],
        [
            'key' => 'lens.mount', 'type' => 'enum', 'required' => true, 'comparable' => true,
            'filterable' => true, 'label' => 'Lens mount', 'options' => ['rf', 'e', 'z'],
        ],
        [
            'key' => 'body.stabilized', 'type' => 'boolean', 'required' => false, 'comparable' => true,
            'filterable' => true, 'label' => 'Stabilized',
        ],
    ];
    unset($overrides['attributes']);

    return array_replace([
        'key' => 'camera',
        'label' => 'Camera',
        'comparison_group' => 'cameras',
        'default_template_key' => 'product.default',
        'attributes' => $attributes,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function catalogVariantMatrix(string $productId, int $version, string $pineImageId, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => $productId,
        'version' => $version,
        'options' => [
            ['name' => 'Color', 'values' => ['Pine', 'Chalk']],
            ['name' => 'Size', 'values' => ['S', 'M']],
        ],
        'variants' => [
            [
                'sku' => 'LAMP-PINE-S', 'price_amount' => 2499, 'is_default' => true, 'available' => true,
                'options' => ['Color' => 'Pine', 'Size' => 'S'],
                'image_asset_id' => $pineImageId, 'image_alt' => 'Pine studio lamp',
                'weight_grams' => 1200, 'length_mm' => 380, 'width_mm' => 180, 'height_mm' => 120,
            ],
            [
                'sku' => 'LAMP-CHALK-S', 'price_amount' => 2699, 'is_default' => false, 'available' => true,
                'options' => ['Color' => 'Chalk', 'Size' => 'S'],
            ],
            [
                'sku' => 'LAMP-PINE-M', 'price_amount' => 2599, 'is_default' => false, 'available' => false,
                'options' => ['Color' => 'Pine', 'Size' => 'M'],
            ],
        ],
    ], $overrides);
}
