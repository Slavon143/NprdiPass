<?php

namespace App\Http\Controllers;

use App\Actions\Onboarding\CreateSampleProductAction;
use App\Actions\Onboarding\SubmitFeedbackAction;
use App\Enums\CompanyPermission;
use App\Models\Platform\PlatformIncident;
use App\Models\User;
use App\Services\Onboarding\PublicBetaOnboardingService;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OnboardingController extends Controller
{
    public function index(CurrentCompany $currentCompany, PublicBetaOnboardingService $onboarding): View
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::CompanyView->value, $company);

        return view('onboarding.index', [
            'company' => $company,
            'snapshot' => $onboarding->snapshot($company),
        ]);
    }

    public function support(CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::CompanyView->value, $company);

        return view('onboarding.support', [
            'company' => $company,
            'supportEmail' => $company->settings['support_email'] ?? null,
            'submissionId' => Str::uuid()->toString(),
            'feedback' => PlatformIncident::query()->where('company_id', $company->getKey())
                ->where('reported_by', auth()->id())->where('type', 'customer_feedback')
                ->latest('updated_at')->latest('id')->paginate(10),
        ]);
    }

    public function help(CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::CompanyView->value, $company);

        return view('onboarding.help', [
            'company' => $company,
        ]);
    }

    public function createSampleProduct(Request $request, CurrentCompany $currentCompany, CreateSampleProductAction $action): RedirectResponse
    {
        $company = $currentCompany->require();

        $product = $action->execute($this->actor($request), $company);

        return redirect()
            ->route('catalog.products.show', $product->uuid)
            ->with('success', __('Sample product is ready for onboarding.'));
    }

    public function submitFeedback(Request $request, CurrentCompany $currentCompany, SubmitFeedbackAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'submission_id' => ['required', 'uuid'],
            'category' => ['required', 'in:question,bug,idea'],
            'subject' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $feedback = $action->execute($this->actor($request), $currentCompany->require(),
            (string) $validated['submission_id'], (string) $validated['category'],
            (string) $validated['subject'], (string) $validated['message']);

        return redirect()->route('onboarding.support')
            ->with('success', __('Message received. Reference: :reference', ['reference' => $feedback->uuid]));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
