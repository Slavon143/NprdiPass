<?php

namespace App\Http\Controllers\Integrations;

use App\Actions\Fortnox\CompleteFortnoxCallbackAction;
use App\Actions\Fortnox\ConnectFortnoxAction;
use App\Actions\Fortnox\DisconnectFortnoxAction;
use App\Actions\Fortnox\ResolveFortnoxConflictAction;
use App\Actions\Fortnox\SyncFortnoxArticlesAction;
use App\Enums\CompanyPermission;
use App\Enums\Fortnox\FortnoxConflictStatus;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Http\Controllers\Controller;
use App\Models\Fortnox\FortnoxArticleMapping;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\Fortnox\FortnoxSyncConflict;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Models\User;
use App\Services\Fortnox\FortnoxOAuthService;
use App\Services\Fortnox\FortnoxSubscriptionGuard;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FortnoxController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::FortnoxManage->value, $company);
        $connection = FortnoxConnection::query()->forCompany($company)->first();
        $oauth = app(FortnoxOAuthService::class);
        $authorizationBlocked = false;
        if ($oauth->usesRealProvider()) {
            try {
                app(FortnoxSubscriptionGuard::class)->assertAuthorizationEnabled((string) config('fortnox.client_id'));
                $oauth->assertConnectionAllowed($connection);
            } catch (FortnoxProviderException) {
                $authorizationBlocked = true;
            }
        }

        return view('integrations.fortnox.index', [
            'connection' => $connection,
            'authorizationBlocked' => $authorizationBlocked,
            'syncRuns' => FortnoxSyncRun::query()->forCompany($company)->with('triggeredBy')->latest()->limit(20)->get(),
            'conflicts' => FortnoxSyncConflict::query()->forCompany($company)
                ->with('mapping')
                ->where('status', FortnoxConflictStatus::Open->value)
                ->latest()
                ->get(),
            'mappings' => FortnoxArticleMapping::query()->forCompany($company)
                ->with(['product', 'variant'])
                ->latest()
                ->paginate(25),
        ]);
    }

    public function connect(Request $request, CurrentCompany $currentCompany, ConnectFortnoxAction $action): RedirectResponse
    {
        try {
            $url = $action->execute($this->actor($request), $currentCompany->require());
        } catch (FortnoxProviderException $exception) {
            return redirect()->route('settings.integrations.fortnox.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()->away($url);
    }

    public function callback(Request $request, CurrentCompany $currentCompany, CompleteFortnoxCallbackAction $action): RedirectResponse
    {
        $company = $currentCompany->require();
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $action->execute($this->actor($request), $company, (string) $validated['state'], (string) $validated['code']);
        } catch (ValidationException $exception) {
            return redirect()->route('settings.integrations.fortnox.index')
                ->with('error', $exception->getMessage());
        } catch (FortnoxProviderException $exception) {
            return redirect()->route('settings.integrations.fortnox.index')
                ->with('error', 'Fortnox connection failed: '.$exception->getMessage());
        }

        return redirect()->route('settings.integrations.fortnox.index')
            ->with('success', 'Fortnox connected.');
    }

    public function disconnect(Request $request, CurrentCompany $currentCompany, DisconnectFortnoxAction $action): RedirectResponse
    {
        try {
            $action->execute($this->actor($request), $currentCompany->require());
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Fortnox disconnected.');
    }

    public function sync(Request $request, CurrentCompany $currentCompany, SyncFortnoxArticlesAction $action): RedirectResponse
    {
        try {
            $run = $action->execute($this->actor($request), $currentCompany->require());

            return back()->with('success', sprintf(
                'Fortnox sync completed: %d fetched, %d created, %d updated, %d skipped, %d conflicts.',
                $run->articles_fetched,
                $run->articles_created,
                $run->articles_updated,
                $run->articles_skipped,
                $run->conflicts_raised,
            ));
        } catch (ValidationException|FortnoxProviderException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function resolve(Request $request, CurrentCompany $currentCompany, ResolveFortnoxConflictAction $action, string $conflict): RedirectResponse
    {
        $validated = $request->validate(['resolution' => ['required', 'in:fortnox,nordipass']]);

        try {
            $action->execute($this->actor($request), $currentCompany->require(), $conflict, (string) $validated['resolution']);
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Conflict resolved.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
