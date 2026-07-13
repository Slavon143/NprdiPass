<?php

namespace App\Audit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

class AuditContext
{
    public const REQUEST_ID_ATTRIBUTE = 'nordipass_request_id';

    public function __construct(
        private readonly Application $app,
    ) {}

    /** @return array{ip_address: ?string, user_agent: ?string, request_id: ?string} */
    public function metadata(): array
    {
        $request = $this->request();
        $requestId = $this->requestId($request);

        if ($request === null || $requestId === null) {
            return [
                'ip_address' => null,
                'user_agent' => null,
                'request_id' => null,
            ];
        }

        return [
            'ip_address' => config('audit.ip_storage', true) ? $this->clientIp() : null,
            'user_agent' => $this->userAgent($request),
            'request_id' => $requestId,
        ];
    }

    public function clientIp(): ?string
    {
        $request = $this->request();

        return $this->requestId($request) === null ? null : $request?->ip();
    }

    private function request(): ?Request
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        return $this->app->make('request');
    }

    private function requestId(?Request $request): ?string
    {
        $requestId = $request?->attributes->get(self::REQUEST_ID_ATTRIBUTE);

        return is_string($requestId) ? $requestId : null;
    }

    private function userAgent(Request $request): ?string
    {
        $userAgent = $request->userAgent();

        if (! is_string($userAgent) || $userAgent === '') {
            return null;
        }

        $withoutControls = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $userAgent);
        $maxLength = min(500, max(0, (int) config('audit.user_agent_max_length', 500)));

        if ($maxLength === 0) {
            return null;
        }

        return mb_substr($withoutControls, 0, $maxLength);
    }
}
