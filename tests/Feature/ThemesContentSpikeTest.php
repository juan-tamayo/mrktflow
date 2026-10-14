<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\RegisterCustomer;
use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Identity\Support\StaffPermissions;
use App\Domain\ThemesContent\Data\ProductView;
use App\Domain\ThemesContent\Rendering\DefaultThemeRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CustomerRegistration;

function themeSpikeProduct(): array
{
    return json_decode(file_get_contents(base_path('themes/default/preview/product.json')), true, flags: JSON_THROW_ON_ERROR);
}

function themeSpikeStaff(string $role): StaffAccount
{
    $staff = new StaffAccount;
    $staff->forceFill(['name' => 'Theme Fixture Staff', 'email' => $role.'@example.test', 'password' => Hash::make('Fictional theme passphrase 471!'), 'role' => $role, 'permissions' => StaffPermissions::forRole($role), 'is_active' => true])->save();

    return $staff;
}

beforeEach(function (): void {
    $this->withoutVite();
    DB::table('installations')->where('id', 1)->update(['status' => 'complete', 'completed_at' => now()]);
});

it('ThemesContentSpike renders relevant product data and a native variant form before JavaScript', function (): void {
    $product = ProductView::from(themeSpikeProduct());
    $html = app(DefaultThemeRenderer::class)->product($product, $product->variants[0]['id'])->render();
    expect($html)->toContain('The studio lamp', 'USD 89.00', 'Illustrative powder-coated steel', 'name="variant"', 'Update view', 'Theme preview');
    expect($html)->not->toContain('Add to cart', 'Buy now');
    expect(json_encode($product, JSON_THROW_ON_ERROR))->not->toContain('customer_id', 'password', 'supplier_cost');
});

it('ThemesContentSpike rejects private fields oversized content and unsafe image paths', function (array $replacement): void {
    expect(fn () => ProductView::from(array_replace_recursive(themeSpikeProduct(), $replacement)))->toThrow(ValidationException::class);
})->with([
    'private cost' => [['supplier_cost' => 1200]],
    'unbounded title' => [['title' => str_repeat('a', 201)]],
    'remote image' => [['image' => ['url' => '//evil.example/image.svg']]],
    'executable image' => [['image' => ['url' => 'javascript:alert(1)']]],
    'traversal image' => [['image' => ['url' => '/images/../private/file']]],
    'unsafe JSON integer' => [['variants' => [0 => ['money' => ['amountMinor' => 9007199254740992]]]]],
]);

it('ThemesContentSpike escapes text in HTML and component data without interpreting it as templates', function (): void {
    $input = themeSpikeProduct();
    $input['title'] = '<img src=x onerror=alert(1)>';
    $input['description'] = '<script>alert(1)</script>';
    $input['variants'][0]['label'] = '<svg onload=alert(1)>';
    $product = ProductView::from($input);
    $html = app(DefaultThemeRenderer::class)->product($product, $product->variants[0]['id'])->render();
    expect($html)->toContain('&lt;img src=x onerror=alert(1)&gt;', '&lt;script&gt;alert(1)&lt;/script&gt;');
    expect($html)->not->toContain('<img src=x', '<script>alert(1)', '<svg onload');
});

it('ThemesContentSpike formats zero and three decimal money exactly without float arithmetic', function (): void {
    $input = themeSpikeProduct();
    $input['variants'][0]['money'] = ['amountMinor' => 9007199254740991, 'currency' => 'JPY', 'exponent' => 0];
    $input['variants'][1]['money'] = ['amountMinor' => 12345, 'currency' => 'KWD', 'exponent' => 3];
    $product = ProductView::from($input);
    expect($product->variants[0]['money']['formatted'])->toBe('JPY 9007199254740991');
    expect($product->variants[1]['money']['formatted'])->toBe('KWD 12.345');
});

it('ThemesContentSpike preview requires staff content permission and rejects customer access', function (): void {
    $this->get('/admin/theme-preview')->assertRedirect('/admin/login');
    $staff = themeSpikeStaff('fulfillment');
    $this->actingAs($staff, 'staff')->withSession(['staff_session_version' => 1])->get('/admin/theme-preview')->assertForbidden();
    $customer = app(RegisterCustomer::class)->register(CustomerRegistration::input());
    auth('staff')->logout();
    $this->actingAs($customer, 'customer')->withSession(['customer_session_version' => 1])->getJson('/admin/theme-preview')->assertUnauthorized();
});

it('ThemesContentSpike accepts native variant selection and never selects a template from request input', function (): void {
    $staff = themeSpikeStaff('content_editor');
    $this->actingAs($staff, 'staff')->withSession(['staff_session_version' => 1]);
    $this->get('/admin/theme-preview?variant=10000000-0000-4000-8000-000000000003&template=../../.env')->assertOk()->assertSee('USD 95.00')->assertSee('The studio lamp')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->getJson('/admin/theme-preview?variant=unknown')->assertUnprocessable();
    $this->get('/')->assertServiceUnavailable()->assertDontSee('The studio lamp');
});
