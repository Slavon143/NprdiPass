<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Taxonomy\TaxonomyGovernanceAction;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\CategoryTemplateStatus;
use App\Http\Controllers\Controller;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Category;
use App\Models\Catalog\CategoryTemplate;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CategoryTemplateController extends Controller
{
    public function show(Request $request, CurrentCompany $currentCompany, string $category): View
    {
        $company = $currentCompany->require();
        $category = $this->category($company, $category);
        $this->authorize('update', $category);
        $templates = CategoryTemplate::query()
            ->forCompany($company)
            ->where('category_id', $category->getKey())
            ->with(['attributes.definition', 'dppDefaults', 'createdBy', 'activatedBy'])
            ->orderByDesc('version')
            ->get();
        $draft = $templates->first(fn (CategoryTemplate $template): bool => $template->status === CategoryTemplateStatus::Draft);

        return view('catalog.categories.template', [
            'company' => $company,
            'category' => $category,
            'templates' => $templates,
            'draft' => $draft,
            'activeTemplate' => $templates->first(fn (CategoryTemplate $template): bool => $template->status === CategoryTemplateStatus::Active),
            'attributeDefinitions' => AttributeDefinition::query()
                ->forCompany($company)
                ->where('status', AttributeDefinitionStatus::Active->value)
                ->ordered()
                ->get(),
            'selectedAttributes' => $draft?->attributes->keyBy('attribute_definition_id') ?? collect(),
            'dppDefaultsJson' => json_encode(
                $draft?->dppDefaults->mapWithKeys(fn ($default): array => [$default->section_key => $default->default_data])->all() ?? [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        ]);
    }

    public function store(
        Request $request,
        CurrentCompany $currentCompany,
        TaxonomyGovernanceAction $action,
        string $category,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $category = $this->category($company, $category);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $template = $action->createTemplate($this->actor($request), $company, $category, $validated);
        $active = $category->activeTemplate()->with(['attributes.definition', 'dppDefaults'])->first();

        if ($active instanceof CategoryTemplate) {
            $action->configureTemplate(
                $this->actor($request),
                $company,
                $category,
                $template,
                $validated['name'],
                $active->attributes->map(fn ($assignment): array => [
                    'attribute_uuid' => $assignment->definition->uuid,
                    'requirement' => $assignment->requirement->value,
                    'sort_order' => $assignment->sort_order,
                ])->values()->all(),
                $active->dppDefaults->mapWithKeys(fn ($default): array => [$default->section_key => $default->default_data])->all(),
            );
        }

        return redirect()->route('catalog.categories.template.show', $category->uuid)
            ->with('success', 'Draft taxonomy template created.');
    }

    public function update(
        Request $request,
        CurrentCompany $currentCompany,
        TaxonomyGovernanceAction $action,
        string $category,
        string $template,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $category = $this->category($company, $category);
        $template = $this->template($company, $category, $template);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'attributes' => ['nullable', 'array', 'max:200'],
            'attributes.*' => ['required', 'in:none,required,recommended'],
            'dpp_defaults_json' => ['nullable', 'json'],
        ]);
        $assignments = collect($validated['attributes'] ?? [])
            ->reject(fn (string $requirement): bool => $requirement === 'none')
            ->map(fn (string $requirement, string $uuid): array => [
                'attribute_uuid' => $uuid,
                'requirement' => $requirement,
            ])->values()->all();
        $defaultsJson = $validated['dpp_defaults_json'] ?? null;
        $defaults = is_string($defaultsJson) && trim($defaultsJson) !== ''
            ? json_decode($defaultsJson, true, 512, JSON_THROW_ON_ERROR)
            : [];

        $action->configureTemplate(
            $this->actor($request),
            $company,
            $category,
            $template,
            $validated['name'],
            $assignments,
            is_array($defaults) ? $defaults : [],
        );

        return back()->with('success', 'Draft taxonomy template saved.');
    }

    public function activate(
        Request $request,
        CurrentCompany $currentCompany,
        TaxonomyGovernanceAction $action,
        string $category,
        string $template,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $category = $this->category($company, $category);
        $template = $this->template($company, $category, $template);
        $action->activateTemplate($this->actor($request), $company, $category, $template);

        return back()->with('success', 'Taxonomy template activated. Products in this category now need review.');
    }

    private function category(Company $company, string $uuid): Category
    {
        return Category::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function template(Company $company, Category $category, string $uuid): CategoryTemplate
    {
        return CategoryTemplate::query()->forCompany($company)
            ->where('category_id', $category->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
