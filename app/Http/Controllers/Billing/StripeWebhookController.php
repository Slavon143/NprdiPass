<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\ProcessStripeWebhookAction;
use App\Http\Controllers\Controller;
use App\Services\Billing\StripeWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookVerifier $verifier, ProcessStripeWebhookAction $action): JsonResponse
    {
        try {
            $event = $action->execute($verifier->verifiedPayload($request));
        } catch (ValidationException $exception) {
            return response()->json(['error' => 'invalid_webhook', 'message' => $exception->getMessage()], 400);
        }

        return response()->json([
            'data' => [
                'id' => $event->stripe_event_id,
                'status' => $event->status->value,
            ],
        ]);
    }
}
