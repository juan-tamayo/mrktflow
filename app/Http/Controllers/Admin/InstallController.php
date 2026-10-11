<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\Actions\InstallationPrerequisites;
use App\Domain\Platform\Actions\InstallStore;
use App\Domain\Platform\Actions\SetupAccess;
use App\Domain\Platform\Support\SetupChoices;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InstallController
{
    public function show(Request $request, SetupAccess $access, InstallationPrerequisites $prerequisites): Response
    {
        $installation = DB::table('installations')->where('id', 1)->first();
        abort_unless($installation !== null && $installation->completed_at === null
            && $installation->status !== 'complete' && $installation->secret_digest !== null
            && $installation->secret_expires_at !== null && CarbonImmutable::parse($installation->secret_expires_at)->isFuture(), 404);
        $grant = $request->session()->get('setup_access');
        $unlocked = false;
        if (is_array($grant)) {
            try {
                $access->authorize($grant);
                $unlocked = true;
            } catch (HttpException) {
                $request->session()->forget('setup_access');
            }
        }
        $settings = $unlocked ? DB::table('store_settings')->where('id', 1)->first() : null;

        return Inertia::render('Install', [
            'unlocked' => $unlocked,
            'settings' => $settings === null ? null : json_decode($settings->values, true, flags: JSON_THROW_ON_ERROR),
            'version' => $settings->version ?? 0,
            'status' => $unlocked ? $installation->status : null,
            'checks' => $unlocked ? $prerequisites->report() : [],
            'saved' => $request->session()->get('setup_saved', false),
            'countries' => $unlocked ? app(SetupChoices::class)->countries() : [],
            'currencies' => $unlocked ? app(SetupChoices::class)->currencies() : [],
            'timezones' => $unlocked ? DateTimeZone::listIdentifiers() : [],
        ]);
    }

    public function unlock(Request $request, SetupAccess $access): RedirectResponse
    {
        $request->session()->forget('setup_access');
        $input = $request->validate(['setup_secret' => ['required', 'string', 'size:64']]);
        try {
            $grant = $access->unlock($input['setup_secret']);
        } catch (HttpException) {
            throw ValidationException::withMessages(['setup_secret' => 'The installation secret is incorrect, expired, or no longer available.'])->redirectTo('/setup');
        }
        $request->session()->regenerate(true);
        $request->session()->put('setup_access', $grant);

        return redirect('/setup');
    }

    public function save(Request $request, SetupAccess $access, InstallStore $installer): RedirectResponse
    {
        $grant = $request->session()->get('setup_access');
        abort_unless(is_array($grant), 403, 'Setup access is unavailable.');
        try {
            $access->authorize($grant);
            $input = $request->validate(['settings' => ['required', 'array'], 'version' => ['required', 'integer', 'min:0', 'max:4294967294']]);
            $installer->save($grant, $input['settings'], $request->integer('version'));
        } catch (HttpException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }
            if ($exception->getStatusCode() === 403) {
                $request->session()->forget('setup_access');
                throw ValidationException::withMessages(['setup_secret' => 'Your setup access has expired or changed. Unlock setup again.'])->redirectTo('/setup');
            }
            if ($exception->getStatusCode() === 409) {
                throw ValidationException::withMessages(['settings' => $exception->getMessage()])->redirectTo('/setup');
            }
            throw $exception;
        }

        return redirect('/setup')->with('setup_saved', true);
    }
}
