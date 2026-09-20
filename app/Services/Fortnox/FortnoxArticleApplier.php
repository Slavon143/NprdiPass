<?php

namespace App\Services\Fortnox;

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Actions\Catalog\Variants\UpdateProductVariantAction;
use App\Data\Fortnox\FortnoxArticle;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Fortnox\FortnoxConflictStatus;
use App\Enums\Fortnox\FortnoxField;
use App\Exceptions\Catalog\LifecycleOperationException;
use App\Exceptions\Catalog\ProductOperationException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\Fortnox\FortnoxArticleMapping;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\Fortnox\FortnoxFieldOverride;
use App\Models\Fortnox\FortnoxSyncConflict;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Models\User;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FortnoxArticleApplier
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const SKIPPED = 'skipped';

    public function __construct(
        private readonly CreateProductAction $createProduct,
        private readonly UpdateProductAction $updateProduct,
        private readonly UpdateProductVariantAction $updateVariant,
        private readonly CatalogIdentifierNormalizer $normalizer,
    ) {}

    public function apply(User $actor, Company $company, FortnoxConnection $connection, FortnoxSyncRun $run, FortnoxArticle $article): string
    {
        return DB::transaction(function () use ($actor, $company, $connection, $run, $article): string {
            [$mapping, $newProduct] = $this->resolveMapping($actor, $company, $connection, $article);

            if ($newProduct) {
                $this->writeCommerce($actor, $mapping->product, $article);

                $mapping->forceFill([
                    'fortnox_last_modified' => $article->lastModified,
                    'fortnox_article_id' => $article->id !== '' ? $article->id : null,
                    'last_applied_values' => [
                        FortnoxField::Name->value => $this->bounded($article->name, 255),
                        FortnoxField::Description->value => $this->bounded($article->description, 10000),
                        FortnoxField::Price->value => $this->priceValue($article->price),
                        FortnoxField::Gtin->value => $this->bounded($article->gtin, 100),
                    ],
                ])->save();
                $run->increment('articles_created');

                return self::CREATED;
            }

            $lastModified = $mapping->fortnox_last_modified;
            if ($lastModified !== null && $article->lastModified->lessThanOrEqualTo($lastModified)) {
                $run->increment('articles_skipped');

                return self::SKIPPED;
            }

            $applied = $this->applyFields($actor, $company, $run, $mapping, $article);

            $mapping->forceFill([
                'fortnox_last_modified' => $article->lastModified,
                'fortnox_article_id' => $article->id !== '' ? $article->id : null,
            ])->save();

            if ($applied) {
                $run->increment('articles_updated');

                return self::UPDATED;
            }

            $run->increment('articles_skipped');

            return self::SKIPPED;
        });
    }

    /** @return array{FortnoxArticleMapping, bool} */
    private function resolveMapping(User $actor, Company $company, FortnoxConnection $connection, FortnoxArticle $article): array
    {
        $existing = FortnoxArticleMapping::query()
            ->forCompany($company)
            ->where('connection_id', $connection->getKey())
            ->where('fortnox_article_number', $article->number)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $skuNormalized = $this->normalizer->normalizeSku($article->number);
        $matchedVariant = ProductVariant::query()
            ->forCompany($company)
            ->where('sku_normalized', $skuNormalized)
            ->lockForUpdate()
            ->first();

        $product = $matchedVariant === null
            ? null
            : Product::query()->forCompany($company)->whereKey($matchedVariant->product_id)->first();

        $variant = $matchedVariant;
        $newProduct = false;

        if ($variant === null || $product === null) {
            $product = $this->createProduct($actor, $company, $article);
            $variant = $product->defaultVariant;
            $newProduct = true;

            if ($variant !== null) {
                try {
                    $variant = $this->updateVariant->execute($actor, $company, $product, $variant, [
                        'sku' => $article->number,
                        'gtin' => $this->bounded($article->gtin, 100),
                    ]);
                } catch (ProductOperationException) {
                    $variant = $this->updateVariant->execute($actor, $company, $product, $variant, [
                        'sku' => $article->number,
                    ]);
                }
            }
        }

        $mapping = FortnoxArticleMapping::query()->create([
            'company_id' => $company->getKey(),
            'connection_id' => $connection->getKey(),
            'fortnox_article_number' => $article->number,
            'product_id' => $product->getKey(),
            'variant_id' => $variant?->getKey(),
        ]);

        return [$mapping, $newProduct];
    }

    private function createProduct(User $actor, Company $company, FortnoxArticle $article): Product
    {
        $name = trim((string) $article->name);
        if ($name === '' || mb_strlen($name) > 255) {
            $name = trim($article->number);
        }

        $data = [
            'name' => $name,
            'description' => $this->bounded($article->description, 10000),
        ];

        try {
            return $this->createProduct->execute($actor, $company, $data);
        } catch (ProductOperationException $exception) {
            if ($exception->errorCode !== 'product_slug_conflict') {
                throw $exception;
            }

            $data['name'] = mb_substr($name, 0, 240).' - '.Str::lower(Str::random(6));

            return $this->createProduct->execute($actor, $company, $data);
        }
    }

    private function applyFields(User $actor, Company $company, FortnoxSyncRun $run, FortnoxArticleMapping $mapping, FortnoxArticle $article): bool
    {
        $product = $mapping->product;
        $variant = $mapping->variant;
        if ($product === null || $product->status === ProductStatus::Archived) {
            return false;
        }

        $applied = false;

        foreach ($this->fieldEntries($article) as $fieldValue => $incoming) {
            $field = FortnoxField::from($fieldValue);

            $override = FortnoxFieldOverride::query()
                ->forCompany($company)
                ->where('mapping_id', $mapping->getKey())
                ->where('field', $field->value)
                ->first();

            $current = $this->currentValue($field, $product, $variant);
            $lastApplied = $mapping->last_applied_values[$field->value] ?? null;

            if ($override !== null && $override->source === 'nordipass') {
                continue;
            }

            if ($this->sameValue($incoming, $current)) {
                $this->rememberApplied($mapping, $field, $incoming);

                continue;
            }

            $force = $override !== null && $override->source === 'fortnox';
            if (! $force && $lastApplied !== null && ! $this->sameValue($current, $lastApplied) && ! $this->sameValue($incoming, $lastApplied)) {
                $this->raiseConflict($company, $run, $mapping, $field, $incoming, $current);

                continue;
            }

            if (! $this->writeField($actor, $company, $field, $incoming, $product, $variant, $article)) {
                continue;
            }

            $this->rememberApplied($mapping, $field, $incoming);
            $applied = true;
        }

        return $applied;
    }

    /** @return array<string, string|null> */
    private function fieldEntries(FortnoxArticle $article): array
    {
        return [
            FortnoxField::Name->value => $this->bounded($article->name, 255),
            FortnoxField::Description->value => $this->bounded($article->description, 10000),
            FortnoxField::Price->value => $this->priceValue($article->price),
            FortnoxField::Gtin->value => $this->bounded($article->gtin, 100),
        ];
    }

    private function currentValue(FortnoxField $field, ?Product $product, ?ProductVariant $variant): mixed
    {
        return match ($field) {
            FortnoxField::Name => $product?->name,
            FortnoxField::Description => $product?->description,
            FortnoxField::Price => $product?->commerce['sales_price'] ?? null,
            FortnoxField::Gtin => $variant?->gtin,
            default => null,
        };
    }

    private function writeField(User $actor, Company $company, FortnoxField $field, ?string $incoming, Product $product, ?ProductVariant $variant, FortnoxArticle $article): bool
    {
        try {
            return match ($field) {
                FortnoxField::Name => (bool) $this->updateProduct->execute($actor, $company, $product, ['name' => (string) $incoming]),
                FortnoxField::Description => (bool) $this->updateProduct->execute($actor, $company, $product, ['description' => $incoming]),
                FortnoxField::Price => $this->writeCommerce($actor, $product, $article, $incoming),
                FortnoxField::Gtin => $variant !== null && (bool) $this->updateVariant->execute($actor, $company, $product, $variant, ['gtin' => $incoming]),
                default => false,
            };
        } catch (LifecycleOperationException|ProductOperationException) {
            return false;
        }
    }

    private function raiseConflict(Company $company, FortnoxSyncRun $run, FortnoxArticleMapping $mapping, FortnoxField $field, ?string $incoming, mixed $current): void
    {
        $openExists = FortnoxSyncConflict::query()
            ->forCompany($company)
            ->where('mapping_id', $mapping->getKey())
            ->where('field', $field->value)
            ->where('status', FortnoxConflictStatus::Open->value)
            ->exists();

        if ($openExists) {
            return;
        }

        FortnoxSyncConflict::query()->create([
            'company_id' => $company->getKey(),
            'sync_run_id' => $run->getKey(),
            'mapping_id' => $mapping->getKey(),
            'field' => $field->value,
            'fortnox_value' => ['value' => $incoming],
            'nordipass_value' => ['value' => $current],
            'status' => FortnoxConflictStatus::Open,
        ]);

        $run->increment('conflicts_raised');
    }

    private function rememberApplied(FortnoxArticleMapping $mapping, FortnoxField $field, ?string $value): void
    {
        $values = $mapping->last_applied_values ?? [];
        $values[$field->value] = $value;
        $mapping->last_applied_values = $values;
        $mapping->save();
    }

    private function writeCommerce(User $actor, ?Product $product, ?FortnoxArticle $article = null, ?string $salesPrice = null): bool
    {
        if ($product === null) {
            return false;
        }

        $price = $salesPrice ?? $this->priceValue($article?->price);

        $commerce = $product->commerce ?? [];
        $commerce['sales_price'] = $price;
        $commerce['currency'] = $commerce['currency'] ?? 'SEK';
        $commerce['vat_code'] = $commerce['vat_code'] ?? 'SE25';
        $commerce['source'] = 'Fortnox';
        $commerce['last_synced_at'] = $article?->lastModified->toIso8601String() ?? now()->toIso8601String();

        $product->forceFill([
            'commerce' => $commerce,
            'updated_by' => $actor->getKey(),
        ])->save();

        return true;
    }

    private function priceValue(?float $price): ?string
    {
        return $price === null ? null : number_format($price, 2, '.', '');
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        $left = $left === null ? null : trim((string) $left);
        $right = $right === null ? null : trim((string) $right);
        if ($left === null || $left === '') {
            return $right === null || $right === '';
        }

        return $left === $right;
    }

    private function bounded(?string $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maximum);
    }
}
