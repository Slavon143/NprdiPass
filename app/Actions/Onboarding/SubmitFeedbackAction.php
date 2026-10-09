<?php

namespace App\Actions\Onboarding;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Platform\PlatformIncidentSeverity;
use App\Enums\Platform\PlatformIncidentStatus;
use App\Models\Company;
use App\Models\Platform\PlatformIncident;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitFeedbackAction
{
    public function __construct(private readonly CompanyAuthorizer $authorizer, private readonly AuditLogger $audit) {}

    public function execute(User $actor, Company $company, string $submissionId, string $category, string $subject, string $message): PlatformIncident
    {
        try {
            return DB::transaction(function () use ($actor, $company, $submissionId, $category, $subject, $message): PlatformIncident {
                $this->authorizer->authorize($actor, $company, CompanyPermission::CompanyView);
                $existing = PlatformIncident::query()->where('company_id', $company->getKey())
                    ->where('reported_by', $actor->getKey())->where('type', 'customer_feedback')
                    ->where('uuid', $submissionId)->first();
                if ($existing !== null) {
                    if ($existing->title !== $subject || $existing->description !== $message || ($existing->context['category'] ?? null) !== $category) {
                        throw ValidationException::withMessages(['submission_id' => __('This form was already submitted. Reload the page to send another message.')]);
                    }

                    return $existing;
                }

                $feedback = new PlatformIncident;
                $feedback->forceFill([
                    'uuid' => $submissionId, 'company_id' => $company->getKey(),
                    'reported_by' => $actor->getKey(), 'type' => 'customer_feedback',
                    'severity' => PlatformIncidentSeverity::Info, 'status' => PlatformIncidentStatus::Open,
                    'title' => $subject, 'description' => $message, 'context' => ['category' => $category],
                ])->save();
                $this->audit->logTenant($company, AuditEvent::FeedbackSubmitted, $actor, $feedback, [
                    'feedback_uuid' => $feedback->uuid, 'category' => $category,
                ]);

                return $feedback;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
            throw ValidationException::withMessages(['submission_id' => __('Please reload this page and submit your message again.')]);
        }
    }
}
