<?php

use App\Actions\Catalog\Attributes\ArchiveAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\ArchiveAttributeOptionAction;
use App\Actions\Catalog\Attributes\CreateAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\CreateAttributeOptionAction;
use App\Actions\Catalog\Attributes\ReorderAttributeOptionsAction;
use App\Actions\Catalog\Attributes\RestoreAttributeDefinitionAction;
use App\Actions\Catalog\Attributes\RestoreAttributeOptionAction;
use App\Actions\Catalog\Attributes\UpdateAttributeDefinitionAction;
use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyRole;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\AuditLog;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** @return array{User, Company} */
function r17DefinitionContext(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $actor, 'company_id' => $company, 'role' => $role]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

/** @param array<string, mixed> $overrides */
function r17DefinitionData(array $overrides = []): array
{
    return array_replace([
        'name' => '  Safety Rating  ',
        'code' => ' Safety Rating ',
        'description' => 'Reusable rating',
        'type' => 'text',
        'scope' => 'both',
        'unit' => null,
        'required' => false,
        'filterable' => true,
        'searchable' => true,
        'sort_order' => 10,
        'validation_rules' => ['min_length' => 2, 'max_length' => 20],
    ], $overrides);
}

test('owner and admin create trusted normalized attribute definitions with safe audit', function (CompanyRole $role) {
    [$actor, $company] = r17DefinitionContext($role);
    $foreign = Company::factory()->create();
    $definition = app(CreateAttributeDefinitionAction::class)->execute($actor, $company, [
        ...r17DefinitionData(),
        'company_id' => $foreign->id,
        'created_by' => 999999,
        'status' => 'archived',
    ]);

    expect($definition->company_id)->toBe($company->id)
        ->and($definition->name)->toBe('Safety Rating')
        ->and($definition->code)->toBe('safety_rating')
        ->and($definition->type)->toBe(AttributeDataType::Text)
        ->and($definition->scope)->toBe(AttributeScope::Both)
        ->and($definition->status)->toBe(AttributeDefinitionStatus::Active)
        ->and($definition->created_by)->toBe($actor->id)
        ->and($definition->validation_rules)->toMatchArray(['min_length' => 2, 'max_length' => 20]);

    $audit = AuditLog::query()->where('event', AuditEvent::CatalogAttributeCreated->value)->sole();
    expect($audit->properties->get('attribute_uuid'))->toBe($definition->uuid)
        ->and($audit->properties->get('data_type'))->toBe('text')
        ->and($audit->properties->has('description'))->toBeFalse();
})->with(['owner' => [CompanyRole::Owner], 'admin' => [CompanyRole::Admin]]);

test('editor and viewer cannot manage definitions', function (CompanyRole $role) {
    [$actor, $company] = r17DefinitionContext($role);

    expect(fn () => app(CreateAttributeDefinitionAction::class)->execute($actor, $company, r17DefinitionData()))
        ->toThrow(AuthorizationException::class);
})->with(['editor' => [CompanyRole::Editor], 'viewer' => [CompanyRole::Viewer]]);

test('definition codes are company unique while another company may use the same code', function () {
    [$actor, $company] = r17DefinitionContext();
    $action = app(CreateAttributeDefinitionAction::class);
    $action->execute($actor, $company, r17DefinitionData());

    expect(fn () => $action->execute($actor, $company, r17DefinitionData(['name' => 'Duplicate'])))
        ->toThrow(AttributeOperationException::class, 'already in use');

    $other = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $other]);
    app(CurrentCompany::class)->set($other);
    $crossTenant = $action->execute($actor, $other, r17DefinitionData());

    expect($crossTenant->company_id)->toBe($other->id)
        ->and(AttributeDefinition::query()->where('code', 'safety_rating')->count())->toBe(2);
});

test('validation rules use a type allowlist and reject incompatible structures', function () {
    [$actor, $company] = r17DefinitionContext();
    $action = app(CreateAttributeDefinitionAction::class);

    expect(fn () => $action->execute($actor, $company, r17DefinitionData(['validation_rules' => ['regex' => '/.*/']])))
        ->toThrow(AttributeOperationException::class, 'not allowed')
        ->and(fn () => $action->execute($actor, $company, r17DefinitionData([
            'type' => 'integer',
            'validation_rules' => ['min_length' => 1],
        ])))->toThrow(AttributeOperationException::class, 'not allowed')
        ->and(fn () => $action->execute($actor, $company, r17DefinitionData([
            'type' => 'decimal',
            'validation_rules' => ['min' => '10', 'max' => '2'],
        ])))->toThrow(AttributeOperationException::class, 'may not exceed');
});

test('definition update is no-op safe and protects code type and incompatible scope after use', function () {
    [$actor, $company] = r17DefinitionContext();
    $create = app(CreateAttributeDefinitionAction::class);
    $update = app(UpdateAttributeDefinitionAction::class);
    $definition = $create->execute($actor, $company, r17DefinitionData(['type' => 'select', 'validation_rules' => []]));
    $auditCount = AuditLog::query()->count();

    $update->execute($actor, $company, $definition, r17DefinitionData(['type' => 'select', 'validation_rules' => []]));
    expect(AuditLog::query()->count())->toBe($auditCount);

    app(CreateAttributeOptionAction::class)->execute($actor, $company, $definition, ['label' => 'A', 'code' => 'a', 'sort_order' => 10]);
    expect(fn () => $update->execute($actor, $company, $definition, r17DefinitionData(['type' => 'integer', 'validation_rules' => []])))
        ->toThrow(AttributeOperationException::class, 'Data type cannot be changed');

    $productId = DB::table('products')->insertGetId([
        'uuid' => (string) str()->uuid(), 'company_id' => $company->id, 'name' => 'Temporary', 'slug' => 'temporary',
        'slug_normalized' => 'temporary', 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    ProductAttributeValue::query()->forceCreate([
        'company_id' => $company->id, 'product_id' => $productId, 'attribute_definition_id' => $definition->id, 'value_option_id' => $definition->options()->firstOrFail()->id,
    ]);

    expect(fn () => $update->execute($actor, $company, $definition, r17DefinitionData(['code' => 'changed', 'type' => 'select', 'validation_rules' => []])))
        ->toThrow(AttributeOperationException::class, 'Code cannot be changed')
        ->and(fn () => $update->execute($actor, $company, $definition, r17DefinitionData(['scope' => 'variant', 'type' => 'select', 'validation_rules' => []])))
        ->toThrow(AttributeOperationException::class, 'Scope cannot exclude');
});

test('definition archive and restore preserve options and values and are idempotent', function () {
    [$actor, $company] = r17DefinitionContext();
    $definition = app(CreateAttributeDefinitionAction::class)->execute($actor, $company, r17DefinitionData(['type' => 'select', 'validation_rules' => []]));
    app(CreateAttributeOptionAction::class)->execute($actor, $company, $definition, ['label' => 'A', 'code' => 'a', 'sort_order' => 10]);
    $archive = app(ArchiveAttributeDefinitionAction::class);
    $restore = app(RestoreAttributeDefinitionAction::class);

    $archive->execute($actor, $company, $definition);
    $afterArchive = AuditLog::query()->count();
    $archive->execute($actor, $company, $definition);
    expect($definition->fresh()?->status)->toBe(AttributeDefinitionStatus::Archived)
        ->and($definition->options()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($afterArchive);

    $restore->execute($actor, $company, $definition);
    $afterRestore = AuditLog::query()->count();
    $restore->execute($actor, $company, $definition);
    expect($definition->fresh()?->status)->toBe(AttributeDefinitionStatus::Active)
        ->and(AuditLog::query()->count())->toBe($afterRestore);
});

test('options are select-only tenant-owned immutable after use archivable and deterministically reorderable', function () {
    [$actor, $company] = r17DefinitionContext();
    $definition = app(CreateAttributeDefinitionAction::class)->execute($actor, $company, r17DefinitionData(['type' => 'select', 'validation_rules' => []]));
    $create = app(CreateAttributeOptionAction::class);
    $first = $create->execute($actor, $company, $definition, ['label' => ' First ', 'code' => ' First ', 'sort_order' => 50]);
    $second = $create->execute($actor, $company, $definition, ['label' => 'Second', 'code' => 'second', 'sort_order' => 60]);

    expect($first->code)->toBe('first')
        ->and(fn () => $create->execute($actor, $company, $definition, ['label' => 'Again', 'code' => 'first', 'sort_order' => 0]))
        ->toThrow(AttributeOperationException::class, 'already in use');

    app(ReorderAttributeOptionsAction::class)->execute($actor, $company, $definition, [$second->id, $first->id]);
    expect($second->fresh()?->sort_order)->toBe(10)->and($first->fresh()?->sort_order)->toBe(20);

    $archive = app(ArchiveAttributeOptionAction::class);
    $restore = app(RestoreAttributeOptionAction::class);
    $archive->execute($actor, $company, $definition, $first);
    $count = AuditLog::query()->count();
    $archive->execute($actor, $company, $definition, $first);
    expect($first->fresh()?->status)->toBe(AttributeOptionStatus::Archived)->and(AuditLog::query()->count())->toBe($count);
    $restore->execute($actor, $company, $definition, $first);
    expect($first->fresh()?->status)->toBe(AttributeOptionStatus::Active);

    $text = app(CreateAttributeDefinitionAction::class)->execute($actor, $company, r17DefinitionData(['name' => 'Text', 'code' => 'text', 'validation_rules' => []]));
    expect(fn () => $create->execute($actor, $company, $text, ['label' => 'No', 'code' => 'no', 'sort_order' => 0]))
        ->toThrow(AttributeOperationException::class, 'select and multiselect');
});

test('wrong tenant definition is rejected even when passed directly to an action', function () {
    [$actor, $company] = r17DefinitionContext();
    $foreign = Company::factory()->create();
    $definition = new AttributeDefinition;
    $definition->forceFill([
        'company_id' => $foreign->id, 'name' => 'Foreign', 'code' => 'foreign', 'type' => AttributeDataType::Text,
        'scope' => AttributeScope::Product, 'status' => AttributeDefinitionStatus::Active, 'created_by' => $actor->id,
    ])->save();

    expect(fn () => app(ArchiveAttributeDefinitionAction::class)->execute($actor, $company, $definition))
        ->toThrow(AttributeOperationException::class, 'unavailable');
});
