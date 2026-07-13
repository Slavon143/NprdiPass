<?php

namespace App\Http\Api;

use App\Domain\Api\Exceptions\ApiCompanyInactive;
use App\Domain\Api\Exceptions\ApiTokenAbilityMissing;
use App\Domain\Api\Exceptions\ApiTokenExpired;
use App\Domain\Api\Exceptions\ApiTokenInvalid;
use App\Models\PersonalAccessToken;
use App\Tenancy\Exceptions\CurrentCompanyNotSet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class ApiExceptionRenderer
{
    public function __construct(
        private readonly ApiResponse $response,
    ) {}

    public function render(Throwable $exception, Request $request): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return $this->response->validationError($exception->errors());
        }

        if ($exception instanceof AuthenticationException) {
            return $this->authenticationError($request);
        }

        if ($exception instanceof ApiTokenExpired) {
            return $this->response->error('token_expired', 'The API token has expired.', 401);
        }

        if ($exception instanceof ApiTokenInvalid) {
            return $this->response->error('token_invalid', 'The API token is invalid.', 401);
        }

        if ($exception instanceof ApiTokenAbilityMissing) {
            return $this->response->error(
                'token_ability_missing',
                'The API token does not have the required ability.',
                403,
            );
        }

        if ($exception instanceof ApiCompanyInactive) {
            return $this->response->error(
                'company_inactive',
                'The token company is not active.',
                $exception->status,
            );
        }

        if ($exception instanceof CurrentCompanyNotSet) {
            return $this->response->error(
                'current_company_missing',
                'Current company is not selected.',
                409,
            );
        }

        if ($exception instanceof AuthorizationException) {
            return $this->response->error('forbidden', 'This action is forbidden.', 403);
        }

        if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
            return $this->response->error('resource_not_found', 'The requested resource was not found.', 404);
        }

        if ($exception instanceof TooManyRequestsHttpException) {
            return $this->response->error('rate_limited', 'Too many requests.', 429);
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $this->httpError($exception);
        }

        if ($exception instanceof RequestExceptionInterface) {
            return $this->response->error('validation_error', 'The request could not be processed.', 400);
        }

        return $this->response->error(
            'internal_error',
            'An unexpected error occurred.',
            500,
        );
    }

    private function authenticationError(Request $request): JsonResponse
    {
        $bearer = $request->bearerToken();

        if (! is_string($bearer) || $bearer === '') {
            return $this->response->error('unauthenticated', 'Authentication is required.', 401);
        }

        $token = PersonalAccessToken::findToken($bearer);

        if ($token instanceof PersonalAccessToken && $token->isExpired()) {
            return $this->response->error('token_expired', 'The API token has expired.', 401);
        }

        return $this->response->error('token_invalid', 'The API token is invalid.', 401);
    }

    private function httpError(HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();

        return match ($status) {
            403 => $this->response->error('forbidden', 'This action is forbidden.', 403),
            404 => $this->response->error('resource_not_found', 'The requested resource was not found.', 404),
            409 => $this->response->error('current_company_missing', 'Current company is not selected.', 409),
            423 => $this->response->error('company_inactive', 'The token company is not active.', 423),
            429 => $this->response->error('rate_limited', 'Too many requests.', 429),
            default => $this->response->error('internal_error', 'An unexpected error occurred.', $status),
        };
    }
}
