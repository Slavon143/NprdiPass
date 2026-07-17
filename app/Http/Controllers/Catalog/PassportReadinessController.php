<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\User;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PassportReadinessController extends Controller
{
    public function show(
        Product $product,
        Request $request,
        CurrentCompany $currentCompany,
        ReadinessContextBuilder $builder,
        PassportReadinessEvaluator $evaluator,
    ): View {
        $company = $currentCompany->require();

        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            throw new NotFoundHttpException;
        }

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $context = $builder->build($company, $product);
        $result = $evaluator->evaluate($context);

        return view('passports.readiness', [
            'readiness' => $result,
            'product' => $product,
            'company' => $company,
            'passport' => $context->passport,
        ]);
    }
}
