<?php

namespace App\Services\Dpp;

use App\Data\Passports\Readiness\ReadinessProfileDefinition;
use App\Models\Catalog\Product;
use App\Models\DppProfile;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Illuminate\Database\Eloquent\Collection;

/** Live catalogue for one page only; never fabricated historical evidence. */
class DppReadinessProfileBatch
{
    /** @var array<string, ReadinessProfileDefinition> */
    private array $definitions = [];

    /** @param array<string, DppProfile> $catalogue */
    private function __construct(public readonly array $catalogue) {}

    /** @param Collection<int, Product> $products */
    public static function load(Collection $products): self
    {
        $products->loadMissing([
            'dppProfiles' => fn ($query) => $query->orderByPivot('priority')->orderBy('dpp_profiles.id')->with('requirements'),
            'primaryCategory.activeTemplate',
        ]);
        $pairs = [[(string) config('passport_readiness.profile'), (int) config('passport_readiness.profile_version')]];
        foreach ($products as $product) {
            foreach ($product->dppProfiles as $assigned) {
                $pairs[] = [$assigned->key, $assigned->version];
            }
            $draft = $product->passport?->currentDraftVersion;
            $pairs[] = [$draft?->readiness_profile, $draft?->readiness_profile_version];
            $pairs[] = [$product->readiness_profile_override, $product->readiness_profile_version_override];
            $pairs[] = [$product->primaryCategory?->activeTemplate?->readiness_profile,
                $product->primaryCategory?->activeTemplate?->readiness_profile_version];
        }
        $codes = array_values(array_filter(array_column($pairs, 0), fn ($code) => is_string($code)));
        $versions = array_values(array_filter(array_column($pairs, 1), fn ($version) => is_int($version)));
        $catalogue = [];
        foreach (DppProfile::query()->whereIn('key', array_unique($codes))->whereIn('version', array_unique($versions))
            ->with('requirements')->get() as $profile) {
            $catalogue[self::key($profile->key, $profile->version)] = $profile;
        }

        return new self($catalogue);
    }

    public static function key(string $code, int $version): string
    {
        return $code.':'.$version;
    }

    public function definition(string $code, int $version, ReadinessProfileRepository $repository): ReadinessProfileDefinition
    {
        $key = self::key($code, $version);

        return $this->definitions[$key] ??= $repository->for($code, $version, $this->catalogue[$key] ?? null);
    }
}
