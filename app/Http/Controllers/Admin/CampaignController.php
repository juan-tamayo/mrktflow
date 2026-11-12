<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\StaffAccount;
use App\Domain\Marketing\Actions\ActivateCampaign;
use App\Domain\Marketing\Actions\PauseCampaign;
use App\Domain\Marketing\Actions\PreviewAudience;
use App\Domain\Marketing\Actions\PreviewSchedule;
use App\Domain\Marketing\Actions\ReadCampaigns;
use App\Domain\Marketing\Actions\SaveCampaign;
use App\Domain\Marketing\Support\CampaignAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CampaignController
{
    public function index(Request $request, ReadCampaigns $campaigns): Response
    {
        $staff = $this->viewer($request);

        return Inertia::render('CampaignIndex', [
            'campaigns' => $campaigns->staffCampaigns(),
            'canWrite' => $staff->hasPermission('marketing.write'),
        ]);
    }

    public function create(Request $request, ReadCampaigns $campaigns): Response
    {
        $staff = $this->writer($request);

        return Inertia::render('CampaignEdit', [
            'campaign' => null,
            'categories' => $campaigns->categories(),
            'canWrite' => true,
            'canActivate' => $staff->hasPermission('marketing.activate'),
            'canSend' => $staff->hasPermission('marketing.send'),
            'saved' => session()->get('campaign_saved', false),
            'preview' => session()->get('audience_preview'),
            'schedule' => null,
        ]);
    }

    public function edit(Request $request, string $campaignId, ReadCampaigns $campaigns, PreviewSchedule $schedule): Response
    {
        $staff = $this->viewer($request);
        try {
            $campaign = $campaigns->staffCampaign($campaignId);
        } catch (NotFoundHttpException) {
            abort(404);
        }

        return Inertia::render('CampaignEdit', [
            'campaign' => $campaign,
            'categories' => $campaigns->categories(),
            'canWrite' => $staff->hasPermission('marketing.write'),
            'canActivate' => $staff->hasPermission('marketing.activate'),
            'canSend' => $staff->hasPermission('marketing.send'),
            'saved' => session()->get('campaign_saved', false),
            'preview' => session()->get('audience_preview'),
            'schedule' => $schedule->preview($staff, $campaignId),
        ]);
    }

    public function store(Request $request, SaveCampaign $save): RedirectResponse
    {
        $this->writer($request);

        return $this->persist($request, $save, $this->payload($request, false), '/admin/marketing/campaigns/create');
    }

    public function update(Request $request, string $campaignId, SaveCampaign $save): RedirectResponse
    {
        $this->writer($request);
        $input = $this->payload($request, true);
        $input['id'] = $campaignId;

        return $this->persist($request, $save, $input, '/admin/marketing/campaigns/'.$campaignId);
    }

    public function preview(Request $request, string $campaignId, PreviewAudience $preview): JsonResponse|RedirectResponse
    {
        $staff = $this->viewer($request);
        $result = $this->publicPreview($preview->preview($staff, $campaignId));
        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return redirect('/admin/marketing/campaigns/'.$campaignId)->with('audience_preview', $result);
    }

    public function activate(Request $request, string $campaignId, ActivateCampaign $activate): RedirectResponse
    {
        $staff = $this->actor($request, 'marketing.activate');
        $version = $this->expectedVersion($request);
        try {
            $activate->activate($staff, $campaignId, $version);
        } catch (HttpException $exception) {
            $this->conflict($request, $exception, '/admin/marketing/campaigns/'.$campaignId);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/marketing/campaigns/'.$campaignId);
        }

        return redirect('/admin/marketing/campaigns/'.$campaignId)->with('campaign_saved', true);
    }

    public function pause(Request $request, string $campaignId, PauseCampaign $pause): RedirectResponse
    {
        $staff = $this->actor($request, 'marketing.activate');
        $version = $this->expectedVersion($request);
        try {
            $pause->pause($staff, $campaignId, $version);
        } catch (HttpException $exception) {
            $this->conflict($request, $exception, '/admin/marketing/campaigns/'.$campaignId);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo('/admin/marketing/campaigns/'.$campaignId);
        }

        return redirect('/admin/marketing/campaigns/'.$campaignId)->with('campaign_saved', true);
    }

    public function send(Request $request, string $campaignId, PreviewSchedule $schedule): JsonResponse|RedirectResponse
    {
        $staff = $this->actor($request, 'marketing.send');
        $result = $schedule->dispatch($staff, $campaignId);
        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return redirect('/admin/marketing/campaigns/'.$campaignId)->with('schedule_preview', $result);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $update): array
    {
        $data = $request->validate([
            'campaign' => ['required', 'array'],
            'campaign.name' => ['required', 'string', 'max:120'],
            'campaign.subject' => ['required', 'string', 'max:180'],
            'campaign.body_text' => ['required', 'string', 'max:8000'],
            'campaign.purpose' => ['required', 'string'],
            'campaign.version' => [$update ? 'required' : 'sometimes', 'nullable', 'integer'],
        ])['campaign'];
        if (! $update) {
            unset($data['id'], $data['version']);
        }

        return $data;
    }

    /** @param array<string, mixed> $input */
    private function persist(Request $request, SaveCampaign $save, array $input, string $fallback): RedirectResponse
    {
        $staff = $this->writer($request);
        try {
            $saved = $save->save($staff, $input);
        } catch (HttpException $exception) {
            $this->conflict($request, $exception, $fallback);
        }

        return redirect('/admin/marketing/campaigns/'.$saved['id'])->with('campaign_saved', true);
    }

    private function expectedVersion(Request $request): int
    {
        return (int) $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
        ])['expected_version'];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function publicPreview(array $preview): array
    {
        unset($preview['contact_ids']);

        return $preview;
    }

    private function viewer(Request $request): StaffAccount
    {
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount && CampaignAccess::canView($staff), 403);

        return $staff;
    }

    private function writer(Request $request): StaffAccount
    {
        return $this->actor($request, 'marketing.write');
    }

    private function actor(Request $request, string $permission): StaffAccount
    {
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount && $staff->hasPermission($permission), 403);

        return $staff;
    }

    private function conflict(Request $request, HttpException $exception, string $fallback): never
    {
        if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
            throw ValidationException::withMessages(['campaign' => $exception->getMessage()])->redirectTo($fallback);
        }
        throw $exception;
    }
}
