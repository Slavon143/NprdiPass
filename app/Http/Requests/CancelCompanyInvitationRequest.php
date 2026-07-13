<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class CancelCompanyInvitationRequest extends FormRequest
{
    private ?CompanyInvitation $resolvedInvitation = null;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('delete', $this->invitation());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function invitation(): CompanyInvitation
    {
        if ($this->resolvedInvitation !== null) {
            return $this->resolvedInvitation;
        }

        $company = $this->attributes->get('currentCompany');
        $invitationUuid = $this->route('invitation');

        abort_unless($company instanceof Company, 404);
        abort_unless(is_string($invitationUuid), 404);

        return $this->resolvedInvitation = $company->invitations()
            ->where('uuid', $invitationUuid)
            ->firstOrFail();
    }
}
