<?php

namespace App\Http\Requests\Catalog\Documents;

use App\Enums\Documents\ProductDocumentType;
use App\Models\Catalog\ProductDocument;
use Illuminate\Validation\Rule;

class AddDocumentVersionRequest extends DocumentRequest
{
    protected ?ProductDocument $document = null;

    public function authorize(): bool
    {
        $actor = $this->actor();
        $company = $this->currentCompany();
        $document = $this->resolveDocument();

        return $actor !== null && $company !== null && $document !== null
            && $actor->can('addVersion', $document);
    }

    public function rules(): array
    {
        $allowedTypes = array_map(
            fn (ProductDocumentType $t) => $t->value,
            ProductDocumentType::cases(),
        );

        return [
            'document_type' => ['required', 'string', Rule::in($allowedTypes)],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'language' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[A-Z]{2,3})?$/'],
            'visibility' => ['required', 'string', Rule::in(['internal', 'passport_public'])],
            'issuer_name' => ['nullable', 'string', 'max:500'],
            'issue_date' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'file' => ['required', 'file', 'max:'.(int) config('documents.max_size_kb', 25600)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->cleanString('title'),
            'description' => $this->cleanNullableString('description'),
            'language' => $this->cleanString('language'),
            'issuer_name' => $this->cleanNullableString('issuer_name'),
        ]);
    }

    public function resolveDocument(): ?ProductDocument
    {
        if ($this->document !== null) {
            return $this->document;
        }

        $uuid = $this->route('document');
        $company = $this->currentCompany();

        if ($uuid === null || $company === null) {
            return null;
        }

        $this->document = ProductDocument::query()
            ->forCompany($company)
            ->where('uuid', $uuid)
            ->first();

        return $this->document;
    }
}
