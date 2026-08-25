<header class="print-document-header">
    <div class="print-brand"><x-organization-logo variant="full" class="print-brand-logo" /><div><p class="print-brand-name">{{ $activeOrganization->name }}</p><p class="print-muted">{{ $audience }}</p></div></div>
    <div class="print-document-identity"><p class="print-kicker">{{ $profile }}</p><h1>{{ $ticketNumber }}</h1><p><strong>Status:</strong> {{ Str::headline($stateLabel) }}</p></div>
</header>
<p class="print-snapshot">Generated {{ $generatedAt->format('M j, Y g:i A T') }} from the live record. Schedule, work, and status reflect the record at that time.</p>
