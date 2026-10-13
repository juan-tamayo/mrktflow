<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\SaveVariants;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Requests\SaveVariantsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ProductVariantController
{
    public function update(SaveVariantsRequest $request, string $productId, SaveVariants $save): RedirectResponse
    {
        Gate::authorize('catalog.write');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);
        $input = $request->validated();
        $input['id'] = $productId;
        try {
            $save->save($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['variants' => $exception->getMessage()])->redirectTo('/admin/products/'.$productId);
            }
            throw $exception;
        }

        return redirect('/admin/products/'.$productId)->with('product_saved', true);
    }
}
