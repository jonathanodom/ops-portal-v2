@if(($acknowledgment['type'] ?? 'none') === 'signed')
    <div class="print-acknowledgment"><p><strong>Signed on-site by:</strong> {{ $acknowledgment['name'] }}@if($acknowledgment['role']) · {{ $acknowledgment['role'] }}@endif</p><p><strong>Signed:</strong> {{ $acknowledgment['signed_at']->timezone($timezone)->format('M j, Y g:i A T') }}</p><p class="print-muted">Statement {{ $acknowledgment['statement_version'] }}</p><img class="print-signature-image" src="{{ $acknowledgment['image_url'] }}" alt="Acknowledgment signature for {{ $acknowledgment['name'] }}"></div>
@elseif(($acknowledgment['type'] ?? 'none') === 'fallback')
    <div class="print-acknowledgment"><p><strong>Acknowledgment fallback:</strong> {{ $acknowledgment['category'] }}</p>@if($acknowledgment['name'])<p><strong>Point of contact:</strong> {{ $acknowledgment['name'] }}@if($acknowledgment['role']) · {{ $acknowledgment['role'] }}@endif</p>@endif @if($acknowledgment['detail'])<p class="print-preserve-lines">{{ $acknowledgment['detail'] }}</p>@endif</div>
@else
    <p class="print-muted">No customer acknowledgment has been recorded for this Closeout version.</p>
@endif
