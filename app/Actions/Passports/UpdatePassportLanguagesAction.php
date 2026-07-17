<?php

namespace App\Actions\Passports;

use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\Localization\PassportLocaleRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdatePassportLanguagesAction
{
    public function __construct(
        private readonly PassportLocaleRegistry $localeRegistry,
    ) {}

    public function handle(
        User $actor,
        Company $company,
        Product $product,
        ProductPassport $passport,
        string $defaultLanguage,
        array $enabledLanguages,
    ): ProductPassport {
        DB::beginTransaction();

        try {
            $this->authorize($actor, $company, $passport, $product);

            $passport = ProductPassport::query()
                ->whereKey($passport->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->localeRegistry->supports($defaultLanguage)) {
                throw new ConflictHttpException("Unsupported default language: {$defaultLanguage}.");
            }

            if (! in_array($defaultLanguage, $enabledLanguages, true)) {
                throw new ConflictHttpException('Default language must be enabled.');
            }

            foreach ($enabledLanguages as $lang) {
                if (! $this->localeRegistry->supports($lang)) {
                    throw new ConflictHttpException("Unsupported language: {$lang}.");
                }
            }

            if (count($enabledLanguages) !== count(array_unique($enabledLanguages))) {
                throw new ConflictHttpException('Duplicate language codes are not allowed.');
            }

            $passport->setAttribute('default_language', $defaultLanguage);
            $passport->setAttribute('enabled_languages', array_values($enabledLanguages));
            $passport->setAttribute('updated_by', $actor->getKey());
            $passport->save();

            DB::commit();

            return $passport->fresh();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function authorize(User $actor, Company $company, ProductPassport $passport, Product $product): void
    {
        if ($company->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        if ((int) $passport->getAttribute('company_id') !== (int) $company->getKey()) {
            throw new NotFoundHttpException;
        }

        if ((int) $passport->getAttribute('product_id') !== (int) $product->getKey()) {
            throw new NotFoundHttpException;
        }

        if (! $actor->can(CompanyPermission::PassportsManage->value, $company)) {
            throw new AuthorizationException;
        }
    }
}
