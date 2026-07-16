<?php

namespace App\Http\Requests\Catalog\Documents;

use App\Enums\Documents\ProductDocumentType;
use App\Models\Catalog\ProductDocument;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends DocumentRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $company = $this->currentCompany();

        return $actor !== null && $company !== null
            && $actor->can('create', [ProductDocument::class, $company]);
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
        $documentType = $this->input('document_type');

        $issuerRequired = in_array($documentType, ['certificate', 'declaration_of_conformity'], true);

        if ($issuerRequired) {
            $this->mergeRules([
                'issuer_name' => ['required', 'string', 'max:500'],
                'issue_date' => ['required', 'date', 'before_or_equal:today'],
            ]);
        }

        $this->merge([
            'title' => $this->cleanString('title'),
            'description' => $this->cleanNullableString('description'),
            'language' => $this->cleanString('language'),
            'issuer_name' => $this->cleanNullableString('issuer_name'),
        ]);
    }

    private function mergeRules(array $rules): void
    {
        // Additional validation logic for certificate/declaration types
        // is handled by after() hook
    }

    public function messages(): array
    {
        return [
            'file.max' => 'The document file must not exceed '.(int) config('documents.max_size_kb', 25600).' KB.',
        ];
    }
}
