<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Fulfillment\Actions\ClaimGuestOrders;
use App\Domain\Fulfillment\Actions\ReadDownloads;
use App\Domain\Fulfillment\Actions\VerifyGuestOrder;
use App\Domain\Identity\Models\CustomerAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Download areas for guests and accounts (FUL-04).
 *
 * The guest flow is scanner-safe by construction: GETs only render forms
 * or the already-granted area. A verification token is consumed solely by
 * the explicit CSRF-protected confirmation POST, which rotates the session
 * and stores a grant scoped to that single order. The account area is
 * keyed strictly by the signed-in customer; a guest grant in the same
 * browser never leaks into it.
 */
final class DownloadsController
{
    private const GRANT_KEY = 'guest_download_grants';

    private const GRANT_TTL_SECONDS = 86400;

    public function accessForm(Request $request): Response
    {
        return response()->view('system.guest-download-access', [
            'signedIn' => $request->user('customer') instanceof CustomerAccount,
        ])->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }

    public function requestAccess(Request $request, VerifyGuestOrder $verify): RedirectResponse
    {
        $input = $request->validate([
            'order_id' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
        ]);
        // The outcome is identical whether or not anything was issued, so
        // this form can never be used to enumerate orders or emails.
        $verify->requestAccess($input['order_id'], $input['email']);

        return redirect('/orders/download-access')
            ->with('status', 'If that order matches that email, a download access message has been queued. Check your inbox for the link or code.');
    }

    public function confirm(Request $request, VerifyGuestOrder $verify): RedirectResponse
    {
        $input = $request->validate(['challenge_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        $orderId = $verify->consume($input['challenge_token']);
        // Rotate before granting: the pre-confirmation session id must
        // never be the one that carries order access.
        $request->session()->regenerate(true);
        $grants = $request->session()->get(self::GRANT_KEY, []);
        $grants = is_array($grants) ? $grants : [];
        $grants[$orderId] = now()->addSeconds(self::GRANT_TTL_SECONDS)->getTimestamp();
        $request->session()->put(self::GRANT_KEY, $grants);

        return redirect('/orders/'.$orderId.'/downloads');
    }

    public function guestDownloads(Request $request, string $orderId, ReadDownloads $downloads): Response
    {
        if (! Str::isUuid($orderId) || $orderId !== strtolower($orderId)) {
            abort(404);
        }
        $grants = $request->session()->get(self::GRANT_KEY, []);
        $expires = is_array($grants) && is_int($grants[$orderId] ?? null) ? $grants[$orderId] : null;
        if ($expires === null || $expires <= now()->getTimestamp()) {
            abort(404);
        }
        $area = $downloads->forOrder($orderId);

        return response()->view('default-theme::account-downloads', [
            'heading' => 'Downloads for order '.$area['number'],
            'orders' => [$area],
            'claimable' => 0,
            'guest' => true,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function accountDownloads(Request $request, ReadDownloads $downloads): Response
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);
        $area = $downloads->forCustomer($customer);

        return response()->view('default-theme::account-downloads', [
            'heading' => 'Your downloads',
            'orders' => $area['orders'],
            'claimable' => $area['claimable'],
            'guest' => false,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function claim(Request $request, ClaimGuestOrders $claim): RedirectResponse
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);
        $claimed = $claim->claim($customer);

        return redirect('/account/downloads')
            ->with('status', $claimed > 0
                ? $claimed.' guest order(s) are now linked to your account.'
                : 'No unclaimed guest orders match your verified email.');
    }
}
