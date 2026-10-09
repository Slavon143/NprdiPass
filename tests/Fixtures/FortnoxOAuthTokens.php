<?php

/** A synthetic provider response; never a credential or a signature verifier. */
function fortnoxTenantJwt(mixed $tenantId, array $extra = []): string
{
    $encode = fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return $encode(['alg' => 'RS256']).'.'.$encode(['tenantId' => $tenantId, ...$extra]).'.fixture-signature';
}
