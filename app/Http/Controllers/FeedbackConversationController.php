<?php

namespace App\Http\Controllers;

use App\Actions\Notifications\MarkNotificationsReadAction;
use App\Actions\Platform\ReplyToFeedbackAction;
use App\Enums\CompanyPermission;
use App\Enums\Notifications\NotificationType;
use App\Enums\PlatformPermission;
use App\Models\Notifications\Notification;
use App\Models\Platform\PlatformIncident;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FeedbackConversationController extends Controller
{
    public function customerShow(Request $request, CurrentCompany $currentCompany, string $feedback, MarkNotificationsReadAction $markRead): View
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::CompanyView->value, $company);
        $actor = $this->actor($request);
        $item = PlatformIncident::query()->where('company_id', $company->getKey())->where('reported_by', $actor->getKey())
            ->where('type', 'customer_feedback')->where('uuid', $feedback)->firstOrFail();
        $replies = $item->replies()->latest('id')->paginate(20);
        $keys = $replies->getCollection()->map(fn ($reply): string => 'feedback-reply:'.$reply->uuid)->all();
        $ids = Notification::query()->forCompany($company)->where('user_id', $actor->getKey())
            ->where('type', NotificationType::FeedbackReply->value)->whereIn('idempotency_key', $keys)
            ->whereNull('read_at')->whereNull('dismissed_at')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $markRead->execute($actor, $company, $ids);

        return view('feedback.conversation', ['feedback' => $item, 'replies' => $replies, 'support' => false, 'submissionId' => Str::uuid()->toString(), 'canReply' => true]);
    }

    public function supportShow(Request $request, string $feedback): View
    {
        $item = PlatformIncident::query()->where('type', 'customer_feedback')->where('uuid', $feedback)->firstOrFail();

        return view('feedback.conversation', [
            'feedback' => $item, 'replies' => $item->replies()->latest('id')->paginate(20), 'support' => true,
            'submissionId' => Str::uuid()->toString(), 'canReply' => $this->actor($request)->can(PlatformPermission::FeedbackManage->value),
        ]);
    }

    public function customerReply(Request $request, CurrentCompany $currentCompany, string $feedback, ReplyToFeedbackAction $action): RedirectResponse
    {
        $input = $this->input($request);
        $action->customer($this->actor($request), $currentCompany->require(), $feedback, $input['submission_id'], $input['message']);

        return redirect()->route('onboarding.feedback.show', $feedback)->with('success', __('Reply sent.'));
    }

    public function supportReply(Request $request, string $feedback, ReplyToFeedbackAction $action): RedirectResponse
    {
        $input = $this->input($request);
        $action->support($this->actor($request), $feedback, $input['submission_id'], $input['message']);

        return redirect()->route('platform.operations.feedback.show', $feedback)->with('success', __('Reply sent.'));
    }

    /** @return array{submission_id: string, message: string} */
    private function input(Request $request): array
    {
        $input = $request->validate(['submission_id' => ['required', 'uuid'], 'message' => ['required', 'string', 'min:1', 'max:2000']]);

        return ['submission_id' => (string) $input['submission_id'], 'message' => (string) $input['message']];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
