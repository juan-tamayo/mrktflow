<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Fulfillment\Actions\GrantAssetUpdate;
use App\Domain\Fulfillment\Actions\ResetDownloadLimit;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Support\StaffTwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Deliberate staff download-access actions (FUL-06): grant a buyer a
 * newer asset version or reset their download allowance. Both follow
 * the fulfillment pattern — `fulfillment.manage`, a fresh two-factor
 * session, a required reason and a stable idempotency key — and the
 * domain actions record the order timeline event and platform audit.
 */
final class EntitlementController
{
    public function update(Request $request, string $orderId, string $entitlementId, GrantAssetUpdate $updates, StaffTwoFactor $twoFactor): RedirectResponse
    {
        if (($response = $this->reauth($request, $orderId, $twoFactor)) !== null) {
            return $response;
        }
        $input = $request->validate([
            'version_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        try {
            $updates->grant($this->staff($request), $orderId, $entitlementId, $input, $this->key($request, $input, $orderId));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/orders/'.$orderId);
        }

        return redirect('/admin/orders/'.$orderId)->with('order_download_saved', true);
    }

    public function resetLimit(Request $request, string $orderId, string $entitlementId, ResetDownloadLimit $resets, StaffTwoFactor $twoFactor): RedirectResponse
    {
        if (($response = $this->reauth($request, $orderId, $twoFactor)) !== null) {
            return $response;
        }
        $input = $request->validate([
            'download_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        try {
            $resets->reset($this->staff($request), $orderId, $entitlementId, $input, $this->key($request, $input, $orderId));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/orders/'.$orderId);
        }

        return redirect('/admin/orders/'.$orderId)->with('order_download_saved', true);
    }

    private function reauth(Request $request, string $orderId, StaffTwoFactor $twoFactor): ?RedirectResponse
    {
        Gate::authorize('fulfillment.manage');
        if ($twoFactor->requiresReauthentication($request)) {
            $request->session()->put('staff_reauth_required', true);
            $request->session()->put('staff_reauth_intended', '/admin/orders/'.$orderId);
            abort_if($request->expectsJson(), 403);

            return redirect('/admin/two-factor');
        }

        return null;
    }

    private function staff(Request $request): StaffAccount
    {
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return $staff;
    }

    /** @param array<string, mixed> $input */
    private function key(Request $request, array $input, string $orderId): string
    {
        $key = $request->header('Idempotency-Key') ?? ($input['idempotency_key'] ?? null);
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable download key.'])->redirectTo('/admin/orders/'.$orderId);
        }

        return strtolower($key);
    }
}
