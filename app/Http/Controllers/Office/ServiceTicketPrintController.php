<?php

namespace App\Http\Controllers\Office;

use App\Domain\ServiceTickets\Documents\TechnicianWorkOrderProjection;
use App\Http\Controllers\Controller;
use App\Models\ServiceTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class ServiceTicketPrintController extends Controller
{
    public function __invoke(Request $request, string $serviceTicket, TechnicianWorkOrderProjection $projection): Response
    {
        $organization = $request->attributes->get('organization');
        $ticket = ServiceTicket::query()->forOrganization($organization->id)->findOrFail($serviceTicket);
        Gate::authorize('view', $ticket);

        return response()->view('office.service-tickets.documents.technician-work-order', $projection->build($organization, $ticket))
            ->withHeaders($this->privateHeaders());
    }

    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
