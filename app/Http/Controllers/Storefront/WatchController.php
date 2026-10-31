<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\ShoppingTools\Actions\ReadWatchSubscriptions;
use App\Domain\ShoppingTools\Actions\SubscribeToWatch;
use App\Domain\ShoppingTools\Actions\VerifyWatchEmail;
use App\Domain\ShoppingTools\Support\WishlistOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Watch subscriptions for stock and price alerts (SHOP-03).
 *
 * Subscribing is a public storefront act: a guest supplies an email
 * and gets pending watches plus a queued verification mail intent —
 * the raw verification token is returned by the domain action to
 * this layer only and is never rendered into a response. The
 * verification pair is deliberately asymmetric like download
 * access: the GET only renders the confirmation form (scanners and
 * prefetchers follow GETs, so a GET must never consume a token or
 * change consent), and the explicit POST consumes it exactly once.
 * Cancellation and the subscription list belong to the signed-in
 * customer alone, resolved from the session principal — never from
 * posted input — so no request can touch another contact's watches.
 */
final class WatchController
{
    public function index(Request $request, ReadWatchSubscriptions $read): JsonResponse
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        $customer = $this->customer($request);
        $subscriptions = $customer === null ? [] : $read->forEmail($customer->email);

        return response()->json(['subscriptions' => $subscriptions])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, SubscribeToWatch $subscribe, ReadWatchSubscriptions $read): JsonResponse|RedirectResponse
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        $customer = $this->customer($request);
        $input = $request->all();
        if (! isset($input['email']) && $customer !== null) {
            $input['email'] = $customer->email;
        }
        if ($customer !== null && isset($input['email'])
            && mb_strtolower(trim((string) $input['email']), 'UTF-8') === mb_strtolower(trim($customer->email), 'UTF-8')) {
            $input['customer_id'] = $customer->id;
        }
        try {
            $result = $subscribe->subscribe($input);
        } catch (ValidationException $exception) {
            return $this->failed($request, $exception);
        }
        if ($request->expectsJson() || $request->is('store-api/*')) {
            return response()->json([
                'status' => $result['status'],
                'subscriptions' => $read->forContact($result['contact_id']),
            ])->header('Cache-Control', 'no-store, private');
        }

        return redirect()->back()->with('status', $result['status'] === 'active'
            ? 'Your alerts are on.'
            : 'Check your email to confirm your alerts.');
    }

    public function cancel(Request $request, string $subscriptionId, SubscribeToWatch $watch, ReadWatchSubscriptions $read): JsonResponse|RedirectResponse
    {
        abort_unless(DB::table('installations')->where('id', 1)->value('status') === 'complete', 503);
        $customer = $this->customer($request);
        abort_if($customer === null, 404);
        $contact = DB::table('delivery_contacts')
            ->where('customer_id', $customer->id)
            ->orWhere('email', mb_strtolower(trim($customer->email), 'UTF-8'))
            ->first();
        abort_if($contact === null, 404);
        abort_unless($watch->cancel((string) $contact->id, $subscriptionId), 404);
        if ($request->expectsJson() || $request->is('store-api/*')) {
            return response()->json(['subscriptions' => $read->forContact((string) $contact->id)])
                ->header('Cache-Control', 'no-store, private');
        }

        return redirect()->back()->with('status', 'That alert is off.');
    }

    public function verifyForm(Request $request, VerifyWatchEmail $verify): Response
    {
        $token = (string) $request->query('token', '');
        abort_unless($verify->pending($token), 404);

        return response()->view('system.watch-verification', [
            'token' => $token,
            'done' => false,
        ])->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }

    public function verify(Request $request, VerifyWatchEmail $verify): Response
    {
        $verify->consume((string) $request->input('token', ''));

        return response()->view('system.watch-verification', [
            'token' => '',
            'done' => true,
        ])->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * The session principal, resolved exactly like the wishlist
     * owner: a signed-in customer whose session version still
     * matches. Guests resolve to null — their management path is
     * the emailed verification and unsubscribe links.
     */
    private function customer(Request $request): ?CustomerAccount
    {
        $owner = WishlistOwner::fromRequest($request);
        if ($owner->customerId === null) {
            return null;
        }
        $customer = CustomerAccount::query()->find($owner->customerId);

        return $customer instanceof CustomerAccount ? $customer : null;
    }

    private function failed(Request $request, ValidationException $exception): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('store-api/*')) {
            $requestId = $request->attributes->get('correlation_id');

            return response()->json([
                'error' => [
                    'code' => 'VALIDATION',
                    'message' => $exception->getMessage(),
                    'fields' => (object) $exception->errors(),
                    'requestId' => is_string($requestId) ? $requestId : '',
                ],
            ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY)->header('Cache-Control', 'no-store, private');
        }

        return redirect()->back()->withErrors($exception->errors());
    }
}
