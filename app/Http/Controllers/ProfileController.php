<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Domain\Companies\Exceptions\LastCompanyOwnerCannotBeRemoved;
use App\Enums\AuditEvent;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use App\Services\Privacy\AccountErasureService;
use App\Services\Privacy\GdprExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account (GDPR erasure).
     */
    public function destroy(Request $request, AccountErasureService $erasure): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $erasure->erase($user);
        } catch (LastCompanyOwnerCannotBeRemoved) {
            return back()->with('error', __('You are the last owner of a company. Transfer ownership before deleting your account.'));
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Download a machine-readable export of the user's personal data (GDPR access).
     */
    public function export(Request $request, GdprExportService $exporter, AuditLogger $auditLogger): Response
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $export = $exporter->build($user);

        $auditLogger->logPlatform(AuditEvent::GdprDataExport, $user, null, [
            'export_format' => 'nordipass-gdpr-export',
            'format_version' => 1,
        ]);

        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="nordipass-data-export.json"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
