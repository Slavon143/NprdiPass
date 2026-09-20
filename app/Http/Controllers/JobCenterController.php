<?php

namespace App\Http\Controllers;

use App\Models\Jobs\JobStatus;
use App\Models\User;
use App\Services\Jobs\JobStatusTracker;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class JobCenterController extends Controller
{
    public function index(CurrentCompany $currentCompany): View
    {
        $jobs = JobStatus::query()
            ->forCompany($currentCompany->require())
            ->latest()
            ->paginate(25);

        return view('jobs.index', ['jobs' => $jobs]);
    }

    public function show(CurrentCompany $currentCompany, string $job): View
    {
        $model = $this->resolveJob($currentCompany, $job);

        return view('jobs.show', ['job' => $model]);
    }

    public function cancel(CurrentCompany $currentCompany, JobStatusTracker $tracker, string $job): RedirectResponse
    {
        try {
            $tracker->requestCancel($this->resolveJob($currentCompany, $job));
        } catch (ConflictHttpException $exception) {
            return back()->withErrors(['job' => $exception->getMessage()]);
        }

        return back()->with('status', __('Job cancellation requested.'));
    }

    public function retry(Request $request, CurrentCompany $currentCompany, JobStatusTracker $tracker, string $job): RedirectResponse
    {
        try {
            $newJob = $tracker->retry($this->resolveJob($currentCompany, $job), $this->actor($request));
        } catch (ConflictHttpException $exception) {
            return back()->withErrors(['job' => $exception->getMessage()]);
        }

        return redirect()->route('job-center.show', $newJob)->with('status', __('Job retry queued.'));
    }

    private function resolveJob(CurrentCompany $currentCompany, string $uuid): JobStatus
    {
        return JobStatus::query()
            ->forCompany($currentCompany->require())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
