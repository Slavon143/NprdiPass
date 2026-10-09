<?php

namespace App\Http\Controllers;

use App\Models\Jobs\JobStatus;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\View\View;

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

    private function resolveJob(CurrentCompany $currentCompany, string $uuid): JobStatus
    {
        return JobStatus::query()
            ->forCompany($currentCompany->require())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
