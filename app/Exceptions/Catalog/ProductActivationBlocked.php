<?php

namespace App\Exceptions\Catalog;

use App\Data\Catalog\Lifecycle\ProductActivationReadiness;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductActivationBlocked extends DomainException
{
    public function __construct(public readonly ProductActivationReadiness $readiness)
    {
        parent::__construct('Resolve all readiness blockers before activation.');
    }

    /** @return list<string> */
    public function blockerCodes(): array
    {
        return $this->readiness->blockerCodes();
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'readiness' => $this->readiness->toArray(),
            ], 422);
        }

        return back()->withErrors(['lifecycle' => $this->getMessage()]);
    }
}
