<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\AiAuthoring\Actions\ReadAiSettings;
use App\Domain\AiAuthoring\Actions\SaveAiSettings;
use App\Domain\Identity\Models\StaffAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AiSettingsController
{
    public function show(Request $request, ReadAiSettings $settings): Response
    {
        $staff = $this->owner($request);

        return Inertia::render('AiSettings', [
            'staff' => ['name' => $staff->name, 'role' => $staff->role],
            'settings' => $settings->forOwner(),
            'authors' => $settings->authorCandidates(),
            'saved' => session()->get('ai_saved', false),
        ]);
    }

    public function update(Request $request, SaveAiSettings $save): RedirectResponse
    {
        $staff = $this->owner($request);
        $input = $request->validate([
            'settings' => ['required', 'array'],
        ])['settings'];
        abort_unless(is_array($input), 422);
        try {
            $save->save($staff, $input);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                throw ValidationException::withMessages(['settings' => $exception->getMessage()])->redirectTo('/admin/ai');
            }
            throw $exception;
        }

        return redirect('/admin/ai')->with('ai_saved', true);
    }

    private function owner(Request $request): StaffAccount
    {
        $staff = $request->user('staff');
        abort_unless($staff instanceof StaffAccount && $staff->role === 'owner', 403);

        return $staff;
    }
}
