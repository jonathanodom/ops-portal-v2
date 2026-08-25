<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\CloseoutAcknowledgmentSignature;
use App\Models\ServiceTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ServiceTicketDocumentSignatureController extends Controller
{
    public function __invoke(Request $request, string $serviceTicket, string $signature): StreamedResponse
    {
        $organization = $request->attributes->get('organization');
        $ticket = ServiceTicket::query()->forOrganization($organization->id)->findOrFail($serviceTicket);
        Gate::authorize('view', $ticket);
        $signature = CloseoutAcknowledgmentSignature::query()->where('organization_id', $organization->id)
            ->whereHas('closeout.visit', fn ($query) => $query->where('service_ticket_id', $ticket->id))
            ->findOrFail($signature);
        abort_unless(Storage::disk($signature->storage_disk)->exists($signature->storage_key), 404);

        return Storage::disk($signature->storage_disk)->response($signature->storage_key, null, [
            'Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
