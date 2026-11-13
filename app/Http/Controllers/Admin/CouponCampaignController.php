<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Pricing\Actions\ImportRecipientGrants;
use App\Domain\Pricing\Actions\IssuePromotionGrant;
use App\Domain\Pricing\Actions\ReadPromotionCampaigns;
use App\Domain\Pricing\Actions\RequestCouponBatch;
use App\Domain\Pricing\Actions\SavePromotionCampaign;
use App\Domain\Pricing\Exceptions\PricingCouponConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CouponCampaignController
{
    public function index(Request $request, ReadPromotionCampaigns $campaigns): Response
    {
        Gate::authorize('pricing.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return Inertia::render('CouponCampaignIndex', [
            'campaigns' => $campaigns->staffCampaigns(),
            'canWrite' => $staff->hasPermission('pricing.manage'),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('pricing.manage');

        return Inertia::render('CouponCampaign', [
            'campaign' => null,
            'saved' => session()->get('promotion_saved', false),
        ]);
    }

    public function edit(string $campaignId, ReadPromotionCampaigns $campaigns): Response
    {
        Gate::authorize('pricing.manage');
        try {
            $campaign = $campaigns->staffCampaign($campaignId);
        } catch (NotFoundHttpException) {
            abort(404);
        }

        return Inertia::render('CouponCampaign', [
            'campaign' => $campaign,
            'saved' => session()->get('promotion_saved', false),
        ]);
    }

    public function store(Request $request, SavePromotionCampaign $save): RedirectResponse
    {
        Gate::authorize('pricing.manage');

        return $this->persist($request, $save, $this->campaignPayload($request, false), '/admin/coupon-campaigns/create');
    }

    public function update(Request $request, string $campaignId, SavePromotionCampaign $save): RedirectResponse
    {
        Gate::authorize('pricing.manage');
        $input = $this->campaignPayload($request, true);
        $input['id'] = $campaignId;

        return $this->persist($request, $save, $input, '/admin/coupon-campaigns/'.$campaignId);
    }

    public function grant(Request $request, string $campaignId, IssuePromotionGrant $issue): RedirectResponse
    {
        Gate::authorize('pricing.manage');
        $data = $request->validate([
            'grant' => ['required', 'array'],
            'grant.source_intent_id' => ['required', 'string', 'max:190'],
            'grant.code' => ['nullable', 'string', 'max:32'],
            'grant.recipient_customer_id' => ['nullable', 'uuid'],
            'grant.expires_at' => ['nullable', 'date'],
        ])['grant'];
        try {
            $issue->issue([
                'campaign_id' => $campaignId,
                'issuer' => 'staff',
                'source_intent_id' => $data['source_intent_id'],
                'code' => $data['code'] ?? null,
                'recipient_customer_id' => $data['recipient_customer_id'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ]);
        } catch (PricingCouponConflict) {
            throw ValidationException::withMessages(['grant' => 'This grant intent already issued a different benefit.'])->redirectTo('/admin/coupon-campaigns/'.$campaignId);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/coupon-campaigns/'.$campaignId);
        }

        return redirect('/admin/coupon-campaigns/'.$campaignId)->with('promotion_saved', true);
    }

    public function batch(Request $request, string $campaignId, RequestCouponBatch $batches): RedirectResponse
    {
        Gate::authorize('pricing.manage');
        $staff = $this->staff($request);
        $data = $request->validate([
            'batch' => ['required', 'array'],
            'batch.source_intent_id' => ['required', 'string', 'max:190'],
            'batch.requested_count' => ['required', 'integer', 'min:1', 'max:1000'],
        ])['batch'];
        try {
            $batches->request($staff, [
                'campaign_id' => $campaignId,
                'source_intent_id' => $data['source_intent_id'],
                'requested_count' => $data['requested_count'],
            ]);
        } catch (PricingCouponConflict) {
            throw ValidationException::withMessages(['batch' => 'This batch intent already issued a different benefit.'])->redirectTo('/admin/coupon-campaigns/'.$campaignId);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/coupon-campaigns/'.$campaignId);
        }

        return redirect('/admin/coupon-campaigns/'.$campaignId)->with('promotion_saved', true);
    }

    public function import(Request $request, string $campaignId, ImportRecipientGrants $imports): RedirectResponse
    {
        Gate::authorize('pricing.manage');
        $staff = $this->staff($request);
        $data = $request->validate([
            'import' => ['required', 'array'],
            'import.source_intent_id' => ['required', 'string', 'max:190'],
            'import.customer_ids' => ['required', 'array', 'min:1', 'max:200'],
            'import.customer_ids.*' => ['uuid', 'distinct:strict'],
            'import.expires_at' => ['nullable', 'date'],
        ])['import'];
        try {
            $imports->import($staff, [
                'campaign_id' => $campaignId,
                'source_intent_id' => $data['source_intent_id'],
                'customer_ids' => $data['customer_ids'],
                'expires_at' => $data['expires_at'] ?? null,
            ]);
        } catch (PricingCouponConflict) {
            throw ValidationException::withMessages(['import' => 'This import intent already issued a different benefit.'])->redirectTo('/admin/coupon-campaigns/'.$campaignId);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/coupon-campaigns/'.$campaignId);
        }

        return redirect('/admin/coupon-campaigns/'.$campaignId)->with('promotion_saved', true);
    }

    /** @return array<string, mixed> */
    private function campaignPayload(Request $request, bool $update): array
    {
        $request->validate([
            'campaign' => ['required', 'array'],
            'campaign.name' => ['required', 'string', 'max:120'],
            'campaign.kind' => ['required', 'string'],
            'campaign.status' => ['required', 'string'],
            'campaign.mode' => ['required', 'string'],
            'campaign.version' => [$update ? 'required' : 'sometimes', 'nullable', 'integer'],
        ]);
        $data = $request->input('campaign');
        if (! is_array($data)) {
            throw ValidationException::withMessages(['campaign' => 'Provide a campaign.']);
        }
        if (! $update) {
            unset($data['id'], $data['version']);
        }

        return $data;
    }

    /** @param array<string, mixed> $input */
    private function persist(Request $request, SavePromotionCampaign $save, array $input, string $fallback): RedirectResponse
    {
        $staff = $this->staff($request);
        try {
            $saved = $save->save($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['campaign' => $exception->getMessage()])->redirectTo($fallback);
            }
            throw $exception;
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($fallback);
        }

        return redirect('/admin/coupon-campaigns/'.$saved['id'])->with('promotion_saved', true);
    }

    private function staff(Request $request): StaffAccount
    {
        Gate::authorize('pricing.manage');
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount, 403);

        return $staff;
    }
}
