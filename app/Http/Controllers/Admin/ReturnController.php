<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Fulfillment\Actions\ApproveReturn;
use App\Domain\Fulfillment\Actions\ReceiveReturn;
use App\Domain\Fulfillment\Actions\RecordReturn;
use App\Domain\Fulfillment\Actions\RestockReturn;
use App\Domain\Identity\Models\StaffAccount;
use App\Http\Support\StaffTwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReturnController
{
    public function store(Request $request, string $orderId, RecordReturn $returns, StaffTwoFactor $twoFactor): RedirectResponse
    {
        if (($response = $this->reauth($request, $orderId, $twoFactor)) !== null) {
            return $response;
        }
        $input = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        try {
            $returns->record($this->staff($request), $orderId, $input, $this->key($request, $input, $orderId));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/orders/'.$orderId);
        }

        return redirect('/admin/orders/'.$orderId)->with('order_return_saved', true);
    }

    public function approve(Request $request, string $orderId, string $returnId, ApproveReturn $returns, StaffTwoFactor $twoFactor): RedirectResponse
    {
        return $this->step($request, $orderId, $twoFactor, fn (StaffAccount $staff, array $input, string $key) => $returns->approve($staff, $orderId, $returnId, $input, $key));
    }

    public function receive(Request $request, string $orderId, string $returnId, ReceiveReturn $returns, StaffTwoFactor $twoFactor): RedirectResponse
    {
        return $this->step($request, $orderId, $twoFactor, fn (StaffAccount $staff, array $input, string $key) => $returns->receive($staff, $orderId, $returnId, $input, $key));
    }

    public function restock(Request $request, string $orderId, string $returnId, RestockReturn $returns, StaffTwoFactor $twoFactor): RedirectResponse
    {
        return $this->step($request, $orderId, $twoFactor, fn (StaffAccount $staff, array $input, string $key) => $returns->restock($staff, $orderId, $returnId, $input, $key));
    }

    /** @param callable(StaffAccount, array<string, mixed>, string): mixed $run */
    private function step(Request $request, string $orderId, StaffTwoFactor $twoFactor, callable $run): RedirectResponse
    {
        if (($response = $this->reauth($request, $orderId, $twoFactor)) !== null) {
            return $response;
        }
        $input = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        try {
            $run($this->staff($request), $input, $this->key($request, $input, $orderId));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/orders/'.$orderId);
        }

        return redirect('/admin/orders/'.$orderId)->with('order_return_saved', true);
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
            throw ValidationException::withMessages(['idempotency_key' => 'Send a stable return key.'])->redirectTo('/admin/orders/'.$orderId);
        }

        return strtolower($key);
    }
}
