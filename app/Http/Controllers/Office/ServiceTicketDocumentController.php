<?php

namespace App\Http\Controllers\Office;

use App\Domain\ServiceTickets\Documents\CompletionSummaryProjection;
use App\Domain\ServiceTickets\Documents\CustomerServiceRecordProjection;
use App\Domain\ServiceTickets\Documents\DetailedServiceReportProjection;
use App\Domain\ServiceTickets\Documents\TechnicianWorkOrderProjection;
use App\Http\Controllers\Controller;
use App\Models\ServiceTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class ServiceTicketDocumentController extends Controller
{
    public function technician(Request $request, string $serviceTicket, TechnicianWorkOrderProjection $projection): Response
    {
        [$organization, $ticket] = $this->resolve($request, $serviceTicket);

        return $this->document('office.service-tickets.documents.technician-work-order', $projection->build($organization, $ticket));
    }

    public function completion(Request $request, string $serviceTicket, CompletionSummaryProjection $projection): Response
    {
        [$organization, $ticket] = $this->resolve($request, $serviceTicket);

        return $this->document('office.service-tickets.documents.completion-summary', $projection->build($organization, $ticket));
    }

    public function customer(Request $request, string $serviceTicket, CustomerServiceRecordProjection $projection): Response
    {
        [$organization, $ticket] = $this->resolve($request, $serviceTicket);

        return $this->document('office.service-tickets.documents.customer-service-record', $projection->build($organization, $ticket));
    }

    public function detailed(Request $request, string $serviceTicket, DetailedServiceReportProjection $projection): Response
    {
        [$organization, $ticket] = $this->resolve($request, $serviceTicket);

        return $this->document('office.service-tickets.documents.detailed-service-report', $projection->build($organization, $ticket, $request->attributes->get('membership')));
    }

    private function resolve(Request $request, string $serviceTicket): array
    {
        $organization = $request->attributes->get('organization');
        $ticket = ServiceTicket::query()->forOrganization($organization->id)->findOrFail($serviceTicket);
        Gate::authorize('view', $ticket);

        return [$organization, $ticket];
    }

    private function document(string $view, array $data): Response
    {
        return response()->view($view, $data)->withHeaders([
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
