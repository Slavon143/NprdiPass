<?php

use App\Enums\DppRequirementLevel;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dpp_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('version');
            $table->string('status', 30)->default('active');
            $table->string('jurisdiction', 100)->nullable();
            $table->string('product_scope', 255)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['key', 'version']);
        });

        Schema::create('dpp_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dpp_profile_id')->constrained()->cascadeOnDelete();
            $table->string('key', 150);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('level', 30);
            $table->string('section', 100)->nullable();
            $table->string('field', 150)->nullable();
            $table->string('source', 150)->nullable();
            $table->string('source_reference', 255)->nullable();
            $table->text('applies_because')->nullable();
            $table->string('rule_code', 150)->nullable();
            $table->string('rule_class', 255)->nullable();
            $table->json('evaluation_config')->nullable();
            $table->json('action_target')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['dpp_profile_id', 'key']);
        });

        Schema::create('product_dpp_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('dpp_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('priority')->default(0);
            $table->string('assignment_reason')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'dpp_profile_id']);
        });

        $repository = app(ReadinessProfileRepository::class);
        foreach (['nordipass-pilot', 'generic-espr'] as $profileKey) {
            $configProfile = config("passport_readiness.profiles.{$profileKey}");
            if (! is_array($configProfile)) {
                continue;
            }

            $version = $profileKey === 'nordipass-pilot'
                ? (int) config('passport_readiness.profile_version', 2)
                : 1;
            $definition = $repository->for($profileKey, $version);
            $profile = DB::table('dpp_profiles')->insertGetId([
                'key' => $profileKey,
                'name' => $definition->name,
                'description' => $definition->description,
                'version' => $version,
                'status' => 'active',
                'jurisdiction' => $profileKey === 'generic-espr' ? 'EU' : null,
                'product_scope' => $profileKey === 'generic-espr' ? 'Generic product passport pilot coverage' : 'NordiPass operational pilot',
                'metadata' => json_encode(['coverage' => $profileKey === 'generic-espr' ? 'incomplete pilot regulatory coverage' : 'operational readiness only']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rules = $definition->ruleClasses;
            $generic = $profileKey === 'generic-espr';
            foreach ($rules as $sort => $ruleClass) {
                $rule = app($ruleClass);
                $level = match ($rule->severity()->value) {
                    'blocker' => DppRequirementLevel::Required->value,
                    'recommendation' => DppRequirementLevel::Recommended->value,
                    default => DppRequirementLevel::Conditional->value,
                };
                if ($generic && ! in_array($rule->group()->value, ['catalog', 'identity', 'manufacturer', 'safety', 'documents', 'certificates', 'recycling', 'environmental'], true)) {
                    continue;
                }
                DB::table('dpp_requirements')->insert([
                    'dpp_profile_id' => $profile,
                    'key' => $rule->code(),
                    'title' => $rule->code(),
                    'description' => null,
                    'level' => $level,
                    'section' => null,
                    'field' => null,
                    'source' => $generic ? 'EU 2024/1781' : 'NordiPass operational profile',
                    'source_reference' => $generic ? 'Annex III (limited pilot mapping)' : null,
                    'applies_because' => $generic ? 'Generic ESPR profile applies to this product.' : 'Default operational profile.',
                    'rule_code' => $rule->code(),
                    'rule_class' => $ruleClass,
                    'evaluation_config' => json_encode([]),
                    'action_target' => json_encode([]),
                    'sort_order' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_dpp_profiles');
        Schema::dropIfExists('dpp_requirements');
        Schema::dropIfExists('dpp_profiles');
    }
};
