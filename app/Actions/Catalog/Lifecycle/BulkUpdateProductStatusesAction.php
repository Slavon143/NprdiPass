<?php

namespace App\Actions\Catalog\Lifecycle;

use App\Enums\Catalog\ProductStatus;
use App\Exceptions\Catalog\LifecycleOperationException;
use App\Exceptions\Catalog\ProductActivationBlocked;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Throwable;

class BulkUpdateProductStatusesAction
{
    public function __construct(
        private readonly ActivateProductAction $activate,
        private readonly ReturnProductToDraftAction $returnToDraft,
        private readonly ArchiveProductAction $archive,
        private readonly RestoreProductAction $restore,
    ) {}

    /**
     * @param  list<string>  $uuids
     * @return array{updated: list<string>, skipped: list<string>, failed: list<string>}
     */
    public function execute(User $actor, Company $company, array $uuids, string $operation): array
    {
        $products = Product::query()
            ->forCompany($company)
            ->whereIn('uuid', $uuids)
            ->orderBy('name')
            ->get()
            ->keyBy('uuid');

        $result = [
            'updated' => [],
            'skipped' => [],
            'failed' => [],
        ];

        foreach ($uuids as $uuid) {
            $product = $products->get($uuid);

            if (! $product instanceof Product) {
                $result['failed'][] = "Unknown product {$uuid}.";

                continue;
            }

            $this->apply($actor, $company, $product, $operation, $result);
        }

        return $result;
    }

    /**
     * @param  array{updated: list<string>, skipped: list<string>, failed: list<string>}  $result
     */
    private function apply(User $actor, Company $company, Product $product, string $operation, array &$result): void
    {
        try {
            match ($operation) {
                'activate' => $this->activateProduct($actor, $company, $product, $result),
                'draft' => $this->returnProductToDraft($actor, $company, $product, $result),
                'archive' => $this->archiveProduct($actor, $company, $product, $result),
                'restore' => $this->restoreProduct($actor, $company, $product, $result),
                default => $result['failed'][] = "{$product->name}: unknown bulk operation.",
            };
        } catch (ProductActivationBlocked $exception) {
            $result['failed'][] = "{$product->name}: not ready for activation (".count($exception->readiness->blockers).' blockers).';
        } catch (LifecycleOperationException $exception) {
            $result['failed'][] = "{$product->name}: {$exception->getMessage()}";
        } catch (Throwable $exception) {
            $result['failed'][] = "{$product->name}: {$exception->getMessage()}";
        }
    }

    /**
     * @param  array{updated: list<string>, skipped: list<string>, failed: list<string>}  $result
     */
    private function activateProduct(User $actor, Company $company, Product $product, array &$result): void
    {
        if ($product->status === ProductStatus::Active) {
            $result['skipped'][] = "{$product->name}: already active.";

            return;
        }

        if ($product->status === ProductStatus::Archived) {
            $result['failed'][] = "{$product->name}: restore archived products before activation.";

            return;
        }

        $this->activate->execute($actor, $company, $product);
        $result['updated'][] = $product->name;
    }

    /**
     * @param  array{updated: list<string>, skipped: list<string>, failed: list<string>}  $result
     */
    private function returnProductToDraft(User $actor, Company $company, Product $product, array &$result): void
    {
        if ($product->status === ProductStatus::Draft) {
            $result['skipped'][] = "{$product->name}: already draft.";

            return;
        }

        if ($product->status === ProductStatus::Archived) {
            $result['failed'][] = "{$product->name}: restore archived products to draft instead.";

            return;
        }

        $this->returnToDraft->execute($actor, $company, $product);
        $result['updated'][] = $product->name;
    }

    /**
     * @param  array{updated: list<string>, skipped: list<string>, failed: list<string>}  $result
     */
    private function archiveProduct(User $actor, Company $company, Product $product, array &$result): void
    {
        if ($product->status === ProductStatus::Archived) {
            $result['skipped'][] = "{$product->name}: already archived.";

            return;
        }

        $this->archive->execute($actor, $company, $product);
        $result['updated'][] = $product->name;
    }

    /**
     * @param  array{updated: list<string>, skipped: list<string>, failed: list<string>}  $result
     */
    private function restoreProduct(User $actor, Company $company, Product $product, array &$result): void
    {
        if ($product->status === ProductStatus::Draft) {
            $result['skipped'][] = "{$product->name}: already draft.";

            return;
        }

        if ($product->status === ProductStatus::Active) {
            $result['failed'][] = "{$product->name}: active products must be returned to draft before restore.";

            return;
        }

        $this->restore->execute($actor, $company, $product);
        $result['updated'][] = $product->name;
    }
}
