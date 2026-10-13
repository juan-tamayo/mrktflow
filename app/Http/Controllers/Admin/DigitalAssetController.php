<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\VersionDigitalAsset;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Requests\AttachDigitalAssetsRequest;
use App\Http\Requests\VersionDigitalAssetRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DigitalAssetController
{
    public function store(VersionDigitalAssetRequest $request, string $productId, VersionDigitalAsset $assets): RedirectResponse
    {
        Gate::authorize('catalog.write');
        Gate::authorize('media.upload');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validated();
        $input['product_id'] = $productId;
        $input['file'] = $request->file('file');
        try {
            $assets->version($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 404) {
                throw $exception;
            }
            if (! $request->expectsJson()) {
                throw ValidationException::withMessages(['file' => $exception->getMessage()])->redirectTo('/admin/products/'.$productId);
            }
            throw $exception;
        }

        return redirect('/admin/products/'.$productId)->with('product_saved', true);
    }

    public function attach(AttachDigitalAssetsRequest $request, string $productId, string $variantId, VersionDigitalAsset $assets): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validated();
        $input['product_id'] = $productId;
        $input['variant_id'] = $variantId;
        try {
            $assets->attach($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 404) {
                throw $exception;
            }
            if (! $request->expectsJson()) {
                throw ValidationException::withMessages(['version_ids' => $exception->getMessage()])->redirectTo('/admin/products/'.$productId);
            }
            throw $exception;
        }

        return redirect('/admin/products/'.$productId)->with('product_saved', true);
    }
}
