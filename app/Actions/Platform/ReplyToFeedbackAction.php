<?php

namespace App\Actions\Platform;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Enums\Notifications\NotificationType;
use App\Enums\Platform\PlatformIncidentStatus;
use App\Enums\PlatformPermission;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Platform\FeedbackReply;
use App\Models\Platform\PlatformIncident;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReplyToFeedbackAction
{
    public function __construct(private readonly CompanyAuthorizer $authorizer, private readonly AuditLogger $audit, private readonly NotificationCenter $notifications) {}

    public function customer(User $actor, Company $company, string $feedbackUuid, string $submissionId, string $message): FeedbackReply
    {
        return $this->transaction(function () use ($actor, $company, $feedbackUuid, $submissionId, $message): FeedbackReply {
            $this->authorizer->authorize($actor, $company, CompanyPermission::CompanyView);
            $feedback = PlatformIncident::query()->where('company_id', $company->getKey())
                ->where('reported_by', $actor->getKey())->where('type', 'customer_feedback')
                ->where('uuid', $feedbackUuid)->lockForUpdate()->firstOrFail();

            return $this->append($actor, $feedback, $submissionId, $message, false);
        });
    }

    public function support(User $actor, string $feedbackUuid, string $submissionId, string $message): FeedbackReply
    {
        return $this->transaction(function () use ($actor, $feedbackUuid, $submissionId, $message): FeedbackReply {
            Gate::forUser($actor)->authorize(PlatformPermission::PlatformAccess->value);
            Gate::forUser($actor)->authorize(PlatformPermission::FeedbackManage->value);
            // Take a company lock before user/resource locks, matching tenant mutations.
            $candidate = PlatformIncident::query()->where('uuid', $feedbackUuid)->where('type', 'customer_feedback')->firstOrFail();
            $company = $candidate->company_id === null ? null : Company::query()->whereKey($candidate->company_id)->sharedLock()->first();
            $currentActor = User::query()->whereKey($actor->getKey())->sharedLock()->first();
            if ($currentActor === null || $currentActor->status !== UserStatus::Active || $currentActor->email_verified_at === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($currentActor)->authorize(PlatformPermission::PlatformAccess->value);
            Gate::forUser($currentActor)->authorize(PlatformPermission::FeedbackManage->value);
            $feedback = PlatformIncident::query()->whereKey($candidate->getKey())->where('type', 'customer_feedback')->lockForUpdate()->firstOrFail();
            abort_unless($feedback->company_id === $candidate->company_id, 409);
            $feedback->setRelation('company', $company);

            return $this->append($currentActor, $feedback, $submissionId, $message, true);
        });
    }

    private function append(User $actor, PlatformIncident $feedback, string $submissionId, string $message, bool $support): FeedbackReply
    {
        Validator::make(['submission_id' => $submissionId, 'message' => $message], [
            'submission_id' => ['required', 'uuid'], 'message' => ['required', 'string', 'min:1', 'max:2000'],
        ])->validate();
        $authorType = $support ? 'support' : 'customer';
        $existing = $feedback->replies()->where('uuid', $submissionId)->first();
        if ($existing !== null) {
            if ($existing->authored_by !== $actor->getKey() || $existing->author_type !== $authorType || $existing->message !== $message) {
                throw ValidationException::withMessages(['submission_id' => __('This reply was already submitted. Reload the conversation to send another reply.')]);
            }

            return $existing;
        }
        $reply = new FeedbackReply;
        $reply->forceFill(['uuid' => $submissionId, 'feedback_id' => $feedback->getKey(), 'authored_by' => $actor->getKey(), 'author_type' => $authorType, 'message' => $message])->save();
        if (! $support && $feedback->status === PlatformIncidentStatus::Resolved) {
            $feedback->forceFill(['status' => PlatformIncidentStatus::Open, 'resolved_by' => null, 'resolved_at' => null])->save();
        }
        $feedback->touch();
        $properties = ['action' => 'feedback.replied', 'feedback_uuid' => $feedback->uuid, 'reply_uuid' => $reply->uuid, 'author_type' => $authorType];
        if ($support) {
            $this->audit->logPlatform(AuditEvent::PlatformAction, $actor, $feedback, $properties);
            $company = $feedback->company;
            $recipient = $feedback->reported_by === null ? null : User::query()->whereKey($feedback->reported_by)->sharedLock()->first();
            if ($company !== null && $company->status === CompanyStatus::Active && $recipient !== null && $recipient->status === UserStatus::Active
                && $company->memberships()->where('user_id', $recipient->getKey())->exists()) {
                $this->notifications->notifyUser($company, $recipient, NotificationType::FeedbackReply,
                    __('Support replied to your request'), __('Open your conversation to read the reply.'), 'feedback-reply:'.$reply->uuid,
                    actionUrl: route('onboarding.feedback.show', $feedback->uuid), payload: ['feedback_uuid' => $feedback->uuid, 'reply_uuid' => $reply->uuid]);
            }
        } else {
            $this->audit->logTenant($feedback->company ?? throw new \LogicException('Customer feedback requires a company.'), AuditEvent::PlatformAction, $actor, $feedback, $properties);
        }

        return $reply;
    }

    private function transaction(\Closure $operation): FeedbackReply
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
            throw ValidationException::withMessages(['submission_id' => __('Please reload this conversation and send your reply again.')]);
        }
    }
}
