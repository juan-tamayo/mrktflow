<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Domain\Identity\Actions\ProveCustomerIdentity;
use App\Domain\Identity\Models\CustomerAccount;
use App\Domain\Platform\Actions\UpdateDeliveryPreference;
use App\Domain\Platform\Support\DeliveryPolicy;
use App\Domain\Platform\Support\UnsubscribeTokens;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Delivery preference surfaces (PLAT-07, MKT-02).
 *
 * The signed one-click unsubscribe pair is deliberately asymmetric: the
 * GET only renders a confirmation form (email scanners and prefetchers
 * follow GETs, so a GET must never change consent), while the POST is
 * the storefront's single CSRF exception — cookie-free and gated solely
 * by the purpose-scoped signature, exactly as one-click unsubscribe
 * requires. Applying it only ever revokes, so replays are harmless.
 * Every other preference edit lives behind the customer session.
 */
final class PreferencesController
{
    /** @var array<string, string> */
    private const PURPOSE_LABELS = [
        'cart_recovery' => 'cart recovery',
        'newsletter' => 'newsletter',
        'stock_alert' => 'stock alert',
        'price_alert' => 'price alert',
    ];

    /**
     * The account preference center is read-only on GET: it renders the
     * signed-in customer's own contact state and creates nothing.
     */
    public function show(Request $request, UpdateDeliveryPreference $preferences): Response
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof CustomerAccount, 403);
        $contact = DB::table('delivery_contacts')
            ->where('customer_id', $customer->id)
            ->orWhere('email', mb_strtolower(trim($customer->email), 'UTF-8'))
            ->first();
        $state = $contact === null
            ? ['email' => $customer->email, 'verified' => false, 'purposes' => array_fill_keys(DeliveryPolicy::PURPOSES, false), 'suppressions' => []]
            : $preferences->state((string) $contact->id);

        return response()->view('default-theme::preferences', [
            'state' => $state,
            'labels' => self::PURPOSE_LABELS,
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Preference edits are protected twice: the customer session gates
     * the route, and identity is re-proved inside the transaction before
     * any consent fact changes (the T050 claiming pattern). A customer
     * can only ever edit the contact bound to their own verified email.
     */
    public function update(Request $request, UpdateDeliveryPreference $preferences): RedirectResponse
    {
        $actor = $request->user('customer');
        abort_unless($actor instanceof CustomerAccount, 403);
        $input = $request->validate([
            'purposes' => ['sometimes', 'array'],
            'purposes.*' => ['string', Rule::in(DeliveryPolicy::PURPOSES)],
            'stop_all' => ['sometimes', 'in:1'],
        ]);
        DB::transaction(function () use ($actor, $input, $preferences): void {
            $identity = app(ProveCustomerIdentity::class)->prove($actor);
            $contactId = $preferences->ensureContact($identity->email, $identity->customerId);
            $preferences->markVerified($contactId, 'customer_account', 'customer', $identity->customerId);
            if (($input['stop_all'] ?? null) === '1') {
                $preferences->revokeAll($contactId, 'preference_center', 'customer', $identity->customerId);
                $preferences->suppress($contactId, 'global', 'opt_out', 'preference_center', 'customer', $identity->customerId);

                return;
            }
            $wanted = $input['purposes'] ?? [];
            foreach (DeliveryPolicy::PURPOSES as $purpose) {
                if (in_array($purpose, $wanted, true)) {
                    $preferences->grant($contactId, $purpose, 'preference_center', 'account_verified', 'customer', $identity->customerId);
                } else {
                    $preferences->revoke($contactId, $purpose, 'preference_center', 'customer', $identity->customerId);
                }
            }
        });

        return redirect('/account/preferences')->with('status', 'Your email preferences are saved.');
    }

    public function unsubscribeForm(Request $request, UnsubscribeTokens $tokens): Response
    {
        $token = (string) $request->query('token', '');
        $parsed = $tokens->parse($token);
        abort_if($parsed === null, 404);

        return response()->view('system.unsubscribe', [
            'token' => $token,
            'purposeLabel' => self::PURPOSE_LABELS[$parsed['purpose']],
            'done' => false,
        ])->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }

    public function unsubscribe(Request $request, UnsubscribeTokens $tokens, UpdateDeliveryPreference $preferences): Response
    {
        $parsed = $tokens->parse((string) $request->input('token', ''));
        abort_if($parsed === null, 404);
        try {
            $preferences->revoke($parsed['contact_id'], $parsed['purpose'], 'unsubscribe_link');
        } catch (DomainException) {
            // The signed contact no longer exists; there is nothing to
            // revoke and nothing to leak, so the outcome is the same page.
        }

        return response()->view('system.unsubscribe', [
            'token' => '',
            'purposeLabel' => self::PURPOSE_LABELS[$parsed['purpose']],
            'done' => true,
        ])->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }
}
