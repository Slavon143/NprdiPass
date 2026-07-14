<?php

namespace App\Actions\Catalog\Exceptions;

use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvalidDefaultVariant extends DomainException
{
    public function __construct()
    {
        parent::__construct('The selected variant cannot be the default for this product.');
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The selected variant cannot be the default for this product.',
                'errors' => ['variant' => [$this->getMessage()]],
            ], 422);
        }

        return back()->withErrors(['variant' => $this->getMessage()]);
    }
}
