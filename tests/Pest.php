<?php

use App\Models\Company;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\NewAccessToken;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('MySql');

pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** @param list<string> $abilities */
function issueCompanyApiToken(
    User $user,
    Company $company,
    array $abilities,
    ?CarbonInterface $expiresAt = null,
    string $name = 'Test token',
): NewAccessToken {
    $newToken = $user->createToken($name, $abilities, $expiresAt ?? now()->addDays(90));
    $token = $newToken->accessToken;

    if (! $token instanceof PersonalAccessToken) {
        throw new RuntimeException('The custom Sanctum token model is not active.');
    }

    $token->forceFill(['company_id' => $company->getKey()])->save();

    return $newToken;
}
