<?php

namespace App\Http\Controllers\Api\V1\Fortnox;

use App\Actions\Fortnox\ResolveFortnoxConflictAction;
use App\Actions\Fortnox\SyncFortnoxArticlesAction;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Fortnox\FortnoxArticleMapping;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\Fortnox\FortnoxSyncConflict;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FortnoxController extends Controller
{
    public function connection(TokenCurrentCompany $currentCompany, ApiResponse $response): JsonResponse
    {
        $company = $currentCompany->require();
        $connection = FortnoxConnection::query()->forCompany($company)->first();

        return $response->success($this->connectionResource($connection));
    }

    public function indexSyncs(TokenCurrentCompany $currentCompany, ApiResponse $response): JsonResponse
    {
        $runs = FortnoxSyncRun::query()->forCompany($currentCompany->require())->latest()->paginate(50);

        return $response->paginated(
            $runs->getCollection()->map(fn (FortnoxSyncRun $run): array => $this->syncRunResource($run))->all(),
            $runs,
        );
    }

    public function sync(Request $request, TokenCurrentCompany $currentCompany, SyncFortnoxArticlesAction $action, ApiResponse $response): JsonResponse
    {
        try {
            $run = $action->execute($this->actor($request), $currentCompany->require());
        } catch (ValidationException $exception) {
            return $response->validationError($exception->errors());
        } catch (FortnoxProviderException $exception) {
            return $response->error(
                'fortnox_provider_error',
                $exception->getMessage(),
                502,
                ['retryable' => $exception->retryable],
            );
        }

        return $response->success($this->syncRunResource($run));
    }

    public function showSync(TokenCurrentCompany $currentCompany, ApiResponse $response, string $syncRun): JsonResponse
    {
        $run = $this->syncRun($currentCompany->require(), $syncRun);
        $run->load('conflicts');

        return $response->success($this->syncRunResource($run, true));
    }

    public function indexMappings(TokenCurrentCompany $currentCompany, ApiResponse $response): JsonResponse
    {
        $mappings = FortnoxArticleMapping::query()->forCompany($currentCompany->require())
            ->with(['product', 'variant'])
            ->latest()
            ->paginate(50);

        return $response->paginated(
            $mappings->getCollection()->map(fn (FortnoxArticleMapping $mapping): array => $this->mappingResource($mapping))->all(),
            $mappings,
        );
    }

    public function indexConflicts(TokenCurrentCompany $currentCompany, ApiResponse $response): JsonResponse
    {
        $conflicts = FortnoxSyncConflict::query()->forCompany($currentCompany->require())
            ->with('mapping')
            ->latest()
            ->paginate(50);

        return $response->paginated(
            $conflicts->getCollection()->map(fn (FortnoxSyncConflict $conflict): array => $this->conflictResource($conflict))->all(),
            $conflicts,
        );
    }

    public function resolve(Request $request, TokenCurrentCompany $currentCompany, ResolveFortnoxConflictAction $action, ApiResponse $response, string $conflict): JsonResponse
    {
        $validated = $request->validate(['resolution' => ['required', 'in:fortnox,nordipass']]);

        try {
            $resolved = $action->execute($this->actor($request), $currentCompany->require(), $conflict, (string) $validated['resolution']);
        } catch (ValidationException $exception) {
            return $response->validationError($exception->errors());
        }

        return $response->success($this->conflictResource($resolved));
    }

    private function syncRun(Company $company, string $uuid): FortnoxSyncRun
    {
        return FortnoxSyncRun::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** @return array<string, mixed> */
    private function connectionResource(?FortnoxConnection $connection): array
    {
        return [
            'connected' => $connection !== null && $connection->status !== FortnoxConnectionStatus::Revoked,
            'status' => $connection?->status->value,
            'provider' => $connection?->provider,
            'scopes' => $connection?->scopes,
            'connected_at' => $connection?->connected_at?->toIso8601String(),
            'last_synced_at' => $connection?->last_synced_at?->toIso8601String(),
            'failure_message' => $connection?->failure_message,
        ];
    }

    /** @return array<string, mixed> */
    private function syncRunResource(FortnoxSyncRun $run, bool $withConflicts = false): array
    {
        return [
            'uuid' => $run->uuid,
            'trigger' => $run->trigger_type->value,
            'status' => $run->status->value,
            'attempts' => $run->attempts,
            'articles_fetched' => $run->articles_fetched,
            'articles_created' => $run->articles_created,
            'articles_updated' => $run->articles_updated,
            'articles_skipped' => $run->articles_skipped,
            'conflicts_raised' => $run->conflicts_raised,
            'failure_message' => $run->failure_message,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'conflicts' => $withConflicts ? $run->conflicts->map(fn (FortnoxSyncConflict $conflict): array => $this->conflictResource($conflict))->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function mappingResource(FortnoxArticleMapping $mapping): array
    {
        return [
            'uuid' => $mapping->uuid,
            'fortnox_article_number' => $mapping->fortnox_article_number,
            'fortnox_last_modified' => $mapping->fortnox_last_modified?->toIso8601String(),
            'product_uuid' => $mapping->product?->uuid,
            'variant_uuid' => $mapping->variant?->uuid,
        ];
    }

    /** @return array<string, mixed> */
    private function conflictResource(FortnoxSyncConflict $conflict): array
    {
        return [
            'uuid' => $conflict->uuid,
            'field' => $conflict->field->value,
            'status' => $conflict->status->value,
            'fortnox_value' => $conflict->fortnox_value,
            'nordipass_value' => $conflict->nordipass_value,
            'article_number' => $conflict->mapping->fortnox_article_number,
            'resolved_at' => $conflict->resolved_at?->toIso8601String(),
        ];
    }
}
