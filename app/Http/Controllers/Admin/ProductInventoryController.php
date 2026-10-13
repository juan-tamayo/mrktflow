<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\AdjustStock;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Requests\AdjustStockRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ProductInventoryController
{
    public function update(AdjustStockRequest $request, string $productId, AdjustStock $adjust): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validated();
        $input['product_id'] = $productId;
        try {
            $adjust->adjust($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 404) {
                throw $exception;
            }
            if (! $request->expectsJson()) {
                throw ValidationException::withMessages(['on_hand' => $exception->getMessage()])->redirectTo('/admin/products/'.$productId);
            }
            throw $exception;
        }

        return redirect('/admin/products/'.$productId)->with('product_saved', true);
    }
}
