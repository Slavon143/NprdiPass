<?php

namespace App\Http\Controllers;

use App\Enums\Notifications\NotificationType;
use App\Models\Notifications\Notification;
use App\Models\Notifications\NotificationPreference;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationCenterController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $user = $this->actor($request);

        $notifications = Notification::query()
            ->forCompany($company)
            ->where('user_id', $user->getKey())
            ->whereNull('dismissed_at')
            ->latest()
            ->paginate(25);

        $preferences = NotificationPreference::query()
            ->forCompany($company)
            ->where('user_id', $user->getKey())
            ->get()
            ->keyBy(fn (NotificationPreference $preference): string => $preference->type->value);

        return view('notifications.index', [
            'notifications' => $notifications,
            'preferences' => $preferences,
            'types' => NotificationType::cases(),
        ]);
    }

    public function markRead(Request $request, CurrentCompany $currentCompany, string $notification): RedirectResponse
    {
        $model = Notification::query()
            ->forCompany($currentCompany->require())
            ->where('user_id', $this->actor($request)->getKey())
            ->where('uuid', $notification)
            ->firstOrFail();

        $model->forceFill(['read_at' => $model->read_at ?? now()])->save();

        return back()->with('status', __('Notification marked as read.'));
    }

    public function markAllRead(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        Notification::query()
            ->forCompany($currentCompany->require())
            ->where('user_id', $this->actor($request)->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('status', __('All notifications marked as read.'));
    }

    public function dismiss(Request $request, CurrentCompany $currentCompany, string $notification): RedirectResponse
    {
        $model = Notification::query()
            ->forCompany($currentCompany->require())
            ->where('user_id', $this->actor($request)->getKey())
            ->where('uuid', $notification)
            ->firstOrFail();

        $model->forceFill(['dismissed_at' => $model->dismissed_at ?? now()])->save();

        return back()->with('status', __('Notification dismissed.'));
    }

    public function updatePreferences(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->require();
        $user = $this->actor($request);
        $validated = $request->validate([
            'preferences' => ['array'],
            'preferences.*.in_app_enabled' => ['nullable', 'boolean'],
            'preferences.*.email_enabled' => ['nullable', 'boolean'],
        ]);

        $preferences = $validated['preferences'] ?? [];

        foreach (NotificationType::cases() as $type) {
            $input = is_array($preferences) && isset($preferences[$type->value]) && is_array($preferences[$type->value])
                ? $preferences[$type->value]
                : [];

            NotificationPreference::query()->updateOrCreate(
                [
                    'company_id' => $company->getKey(),
                    'user_id' => $user->getKey(),
                    'type' => $type,
                ],
                [
                    'in_app_enabled' => (bool) ($input['in_app_enabled'] ?? false),
                    'email_enabled' => (bool) ($input['email_enabled'] ?? false),
                ],
            );
        }

        return back()->with('status', __('Notification preferences saved.'));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
