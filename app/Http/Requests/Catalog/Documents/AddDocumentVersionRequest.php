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
            'certificate_number' => ['nullable', 'string', 'max:120'],
            'issuing_body' => ['nullable', 'string', 'max:255'],
            'declaration_identifier' => ['nullable', 'string', 'max:120'],
            'evidence_type' => ['nullable', 'string', 'max:120'],
            'topic_code' => ['nullable', 'string', 'max:120'],
            'standard_reference' => ['nullable', 'string', 'max:255'],
            'applicable_market' => ['nullable', 'string', 'max:120'],
            'reference_url' => ['nullable', 'url', 'max:1000'],
            'issue_date' => ['nullable', 'date', 'before_or_equal:today'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'metadata' => ['nullable', 'array'],
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
            'certificate_number' => $this->cleanNullableString('certificate_number'),
            'issuing_body' => $this->cleanNullableString('issuing_body'),
            'declaration_identifier' => $this->cleanNullableString('declaration_identifier'),
            'evidence_type' => $this->cleanNullableString('evidence_type'),
            'topic_code' => $this->cleanNullableString('topic_code'),
            'standard_reference' => $this->cleanNullableString('standard_reference'),
            'applicable_market' => $this->cleanNullableString('applicable_market'),
            'reference_url' => $this->cleanNullableString('reference_url'),
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
