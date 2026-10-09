<?php

namespace Tests\Feature\Passports\Web;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Passports\DppSchemaRegistry;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class PassportEditorUxTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['status' => CompanyStatus::Active]);
        $this->actor = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->actor->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($this->actor);
        app(CurrentCompany::class)->set($this->company);

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        $this->product = new Product;
        $this->product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Editor UX Product '.fake()->unique()->word(),
            'slug' => 'editor-ux-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'editor-ux-product-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Active,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
        $this->product->refresh();

        app(CreateProductPassportDraftAction::class)->handle($this->actor, $this->company, $this->product);
    }

    public function test_editor_renders_sections_nav_and_only_the_active_section(): void
    {
        $response = $this->get(route('catalog.products.passport.edit', ['product' => $this->product->uuid]));

        $response->assertOk()
            ->assertSee('Passport sections')
            ->assertSee('Required section')
            ->assertSeeText('Public name')
            ->assertDontSeeText('Emergency instructions');
    }

    public function test_deep_link_opens_the_target_section(): void
    {
        $this->get(route('catalog.products.passport.edit', ['product' => $this->product->uuid, 'section' => DppSectionKey::Safety->value]))
            ->assertOk()
            ->assertSeeText('Emergency instructions')
            ->assertDontSeeText('Public name');
    }

    public function test_editor_uses_human_labels_and_hints_without_technical_terms(): void
    {
        $response = $this->get(route('catalog.products.passport.edit', [
            'product' => $this->product->uuid,
            'section' => DppSectionKey::UsageAndCare->value,
        ]));

        $response->assertOk()
            ->assertSeeText('Usage steps')
            ->assertSeeText('Usage warnings')
            ->assertSeeText('Care steps')
            ->assertSeeText('Care warnings');

        $visibleMarkup = preg_replace('/<script[\s\S]*?<\/script>/i', '', $response->getContent());
        $visibleText = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($visibleMarkup), ENT_QUOTES, 'UTF-8')));
        $this->assertStringNotContainsString('usage_steps', $visibleText);
        $this->assertStringNotContainsString('translatable', $visibleText);

        $this->get(route('catalog.products.passport.edit', ['product' => $this->product->uuid]))
            ->assertOk()
            ->assertSeeText('500 characters maximum')
            ->assertDontSeeText('Max: 500');
    }

    public function test_editor_uses_structured_editors_instead_of_json_textareas(): void
    {
        $response = $this->get(route('catalog.products.passport.edit', [
            'product' => $this->product->uuid,
            'section' => DppSectionKey::RepairAndSpareParts->value,
        ]));

        $response->assertOk()
            ->assertSee('Add spare part')
            ->assertDontSeeText('Enter a JSON array of objects')
            ->assertDontSeeText('json_list');
    }

    public function test_editor_renders_conditional_gates_with_off_labels(): void
    {
        $this->get(route('catalog.products.passport.edit', [
            'product' => $this->product->uuid,
            'section' => DppSectionKey::SupportAndContact->value,
        ]))
            ->assertOk()
            ->assertSeeText('Warranty available')
            ->assertSeeText('No warranty is provided.')
            ->assertSee('values.warranty_available === true', false)
            ->assertSee('values.warranty_available = true', false);
    }

    public function test_gate_questions_render_before_the_fields_they_control(): void
    {
        $content = $this->get(route('catalog.products.passport.edit', [
            'product' => $this->product->uuid,
            'section' => DppSectionKey::RecyclingAndDisposal->value,
        ]))->getContent();

        $gatePosition = strpos($content, 'input-recycling_and_disposal-take_back_program_available-yes');
        $fieldPosition = strpos($content, 'id="field-recycling_and_disposal-take_back_program"');

        $this->assertNotFalse($gatePosition);
        $this->assertNotFalse($fieldPosition);
        $this->assertLessThan($fieldPosition, $gatePosition);
    }

    public function test_editor_shows_inline_readiness_hints_per_field(): void
    {
        $this->get(route('catalog.products.passport.edit', [
            'product' => $this->product->uuid,
            'section' => DppSectionKey::Safety->value,
        ]))
            ->assertOk()
            ->assertSeeText('Add emergency instructions customers can follow')
            ->assertSeeText('Optional for publication; completing it improves readiness.');
    }

    public function test_empty_materials_show_one_next_step_instead_of_three_warnings(): void
    {
        $this->get(route('catalog.products.passport.edit', [
            'product' => $this->product->uuid,
            'section' => DppSectionKey::MaterialsAndComposition->value,
        ]))
            ->assertOk()
            ->assertSeeText('Add at least one material row and enter the material name.')
            ->assertDontSeeText('Enter Product share (%) for every material row.')
            ->assertDontSeeText('Enter Recycled content (%) for at least one material.')
            ->assertSeeText('Product share (%)')
            ->assertSeeText('Recycled content (%)');
    }

    public function test_take_back_warning_first_asks_for_a_yes_or_no_declaration(): void
    {
        $this->get(route('catalog.products.passport.edit', [
            'product' => $this->product->uuid,
            'section' => DppSectionKey::RecyclingAndDisposal->value,
        ]))
            ->assertOk()
            ->assertSeeText('Confirm whether a take-back program is available by selecting Yes or No.')
            ->assertSee('field-recycling_and_disposal-take_back_program_available');
    }

    public function test_every_editor_readiness_target_points_to_a_registered_field(): void
    {
        $result = app(PassportReadinessEvaluator::class)->evaluate(
            app(ReadinessContextBuilder::class)->build($this->company, $this->product),
        );
        $sections = app(DppSchemaRegistry::class)->sections();

        foreach ($result->rules as $rule) {
            if ($rule->section === null || $rule->field === null) {
                continue;
            }

            $fieldKeys = array_map(
                fn ($field): string => $field->key,
                $sections[$rule->section->value]->fields,
            );

            $this->assertContains(
                $rule->field,
                $fieldKeys,
                "Readiness rule {$rule->code} points to an unknown editor field.",
            );
        }
    }

    public function test_disabled_optional_section_shows_enable_state(): void
    {
        $enabledSections = array_values(array_diff(
            array_map(fn ($s) => $s->value, DppSectionKey::cases()),
            [DppSectionKey::EnvironmentalInformation->value],
        ));

        $this->putJson(route('catalog.products.passport.settings.update', ['product' => $this->product->uuid]), [
            'settings' => ['enabled_sections' => $enabledSections],
            'expected_revision' => 1,
        ])->assertOk();

        $this->get(route('catalog.products.passport.edit', [
            'product' => $this->product->uuid,
            'section' => DppSectionKey::EnvironmentalInformation->value,
        ]))
            ->assertOk()
            ->assertSeeText('This optional section is not enabled.')
            ->assertSee('Enable section')
            ->assertDontSeeText('Carbon footprint');
    }

    public function test_viewer_sees_read_only_editor_without_controls(): void
    {
        config(['security.csp_enabled' => true]);

        $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->update(['role' => CompanyRole::Viewer->value]);

        $response = $this->get(route('catalog.products.passport.edit', [
            'product' => $this->product->uuid,
            'section' => DppSectionKey::Safety->value,
        ]))
            ->assertOk()
            ->assertSee('Read-only')
            ->assertDontSee('Save changes')
            ->assertDontSee('Enable section');

        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $nonce);
        preg_match_all('/<script\\b([^>]*)>/', $response->getContent(), $scripts);
        $this->assertCount(2, $nonce);
        foreach ($scripts[1] as $attributes) {
            if (! str_contains($attributes, 'src=')) {
                $this->assertStringContainsString('nonce="'.$nonce[1].'"', $attributes);
            }
        }
        $response->assertSee('x-data="passportSection"', false)
            ->assertSee('window.passportSection = function', false);
    }
}
