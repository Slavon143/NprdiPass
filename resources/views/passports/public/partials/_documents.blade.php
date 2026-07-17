<div class="space-y-3">
    @foreach($passport->documents as $document)
        <div class="document-card">
            <h3 class="text-base font-semibold text-slate-800 mb-2">{{ $document->title }}</h3>
            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1 mb-3">
                <div><dt>Type</dt><dd>{{ ucfirst(str_replace('_', ' ', $document->documentType)) }}</dd></div>
                <div><dt>Language</dt><dd>{{ strtoupper($document->language) }}</dd></div>
                @if($document->issuerName !== null)
                    <div><dt>Issuer</dt><dd>{{ $document->issuerName }}</dd></div>
                @endif
                @if($document->issueDate !== null)
                    <div><dt>Issue Date</dt><dd>{{ $document->issueDate }}</dd></div>
                @endif
                @if($document->expiresAt !== null)
                    <div><dt>Expiry</dt><dd>{{ $document->expiresAt }}</dd></div>
                @endif
                <div><dt>File</dt><dd>{{ strtoupper($document->fileExtension) }} &middot; {{ $document->formattedSize }}</dd></div>
            </dl>
            <a href="{{ route('public.passports.documents.download', ['publicId' => $passport->passportPublicId, 'asset' => $document->assetUuid]) }}"
               class="download-link"
               download>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                View / Download
            </a>
        </div>
    @endforeach

    @if(count($passport->documents) === 0)
        <p class="text-slate-500 text-sm">No public documents available for this passport.</p>
    @endif

    @if(!empty($fields['certification_notes']))
        <div class="mt-3">
            <h3 class="text-sm font-semibold text-slate-700 mb-1">Certification Notes</h3>
            <p class="text-slate-600 text-sm">{!! nl2br(e($fields['certification_notes'])) !!}</p>
        </div>
    @endif

    @if(!empty($fields['compliance_summary']))
        <div class="mt-3">
            <h3 class="text-sm font-semibold text-slate-700 mb-1">Compliance Summary</h3>
            <p class="text-slate-600 text-sm">{!! nl2br(e($fields['compliance_summary'])) !!}</p>
        </div>
    @endif
</div>
