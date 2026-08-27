<?php

namespace App\Domain;

use App\Domain\ServiceTickets\Documents\ServiceTicketDocumentSupport;
use App\Models\Invoice;
use App\Models\ServiceTicket;
use Illuminate\Validation\ValidationException;

final class InvoiceServiceContextProjection
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly ServiceTicketDocumentSupport $support) {}

    /** @return array<string, mixed> */
    public function build(Invoice $invoice): array
    {
        if (! $invoice->service_ticket_id) {
            throw ValidationException::withMessages(['invoice' => 'A direct invoice has no Service Ticket context.']);
        }

        $ticket = ServiceTicket::query()
            ->forOrganization($invoice->organization_id)
            ->with([
                'customer:id,display_name,legal_name',
                'serviceLocation:id,primary_contact_id,name,address_line_1,address_line_2,city,state,postal_code,timezone',
                'serviceLocation.primaryContact:id,name,role,phone,email',
                'contact:id,name,role,phone,email',
                'workItems' => fn ($query) => $query->with([
                    'discoveredVisit:id,ticket_visit_number,return_of_visit_id',
                    'visits:id,ticket_visit_number,return_of_visit_id',
                    'followUpServiceTicket:id,ticket_number',
                ])->orderBy('id')->limit(200),
                'visits' => fn ($query) => $query->with([
                    'returnOfVisit:id,ticket_visit_number',
                    'assignments.membership.user:id,name',
                    'timeEntries:id,visit_id,user_id,category,started_at,ended_at,corrected_started_at,corrected_ended_at',
                    'currentCloseout' => fn ($closeout) => $closeout
                        ->select('id', 'visit_id', 'status', 'outcome', 'work_performed', 'recommendations', 'representative_name', 'representative_role', 'acknowledged_at', 'ack_unavailable_category', 'ack_unavailable_detail')
                        ->with([
                            'acknowledgmentSignature:id,closeout_id,signer_name,signer_role,statement_version,signed_at',
                            'parts' => fn ($parts) => $parts->whereNull('removed_at')->select('id', 'closeout_id', 'description', 'quantity', 'unit')->orderBy('id'),
                        ]),
                ])->orderBy('ticket_visit_number')->limit(200),
            ])
            ->findOrFail($invoice->service_ticket_id);

        if ((int) $ticket->customer_id !== (int) $invoice->customer_id || (int) $ticket->service_location_id !== (int) $invoice->service_location_id) {
            throw ValidationException::withMessages(['invoice' => 'Invoice and Service Ticket customer or location context do not match.']);
        }

        $contact = $ticket->contact ?: $ticket->serviceLocation->primaryContact;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ticket' => [
                'number' => $ticket->ticket_number,
                'title' => $ticket->title,
                'status' => $this->ticketStatus($ticket->status),
            ],
            'customer' => [
                'name' => $ticket->customer->display_name,
                'legal_name' => $ticket->customer->legal_name,
            ],
            'site' => [
                'name' => $ticket->serviceLocation->name,
                'address' => $ticket->serviceLocation->formattedAddress(),
                'timezone' => $ticket->serviceLocation->timezone,
            ],
            'contact' => $contact ? [
                'name' => $contact->name,
                'role' => $contact->role,
                'phone' => $contact->phone,
                'email' => $contact->email,
            ] : null,
            'requested_service' => [
                'scope' => $ticket->description,
                'summary' => $ticket->customer_visible_summary,
            ],
            'work_items' => $ticket->workItems->map(fn ($item): array => [
                'title' => $item->title,
                'status' => $this->workItemStatus($item->status),
                'discovered_visit' => $item->discoveredVisit?->displayNumber(),
                'handled_visits' => $item->visits->sortBy('ticket_visit_number')->map->displayNumber()->values()->all(),
                'follow_up_ticket' => $item->followUpServiceTicket?->ticket_number,
            ])->values()->all(),
            'visits' => $ticket->visits->map(function ($visit): array {
                $closeout = $visit->currentCloseout;
                $siteWindow = $this->support->siteWindow($visit->timeEntries);
                $visitDate = $siteWindow['start']?->copy()->timezone($visit->timezone)->toDateString()
                    ?? $visit->scheduledStartLocal()?->toDateString();

                return [
                    'label' => $visit->displayLabel(),
                    'date' => $visitDate,
                    'status' => $this->support->visitState($visit),
                    'return_of' => $visit->returnOfVisit?->displayNumber(),
                    'timezone' => $visit->timezone,
                    'site_window' => [
                        'start_at' => $siteWindow['start']?->toIso8601String(),
                        'end_at' => $siteWindow['end']?->toIso8601String(),
                    ],
                    'technicians' => $visit->assignments->map(fn ($assignment) => $assignment->membership->user->name)->unique()->sort()->values()->all(),
                    'work_performed' => $closeout?->work_performed,
                    'recommendations' => $closeout?->recommendations,
                    'outcome' => $closeout?->outcome ? str($closeout->outcome)->headline()->toString() : null,
                    'parts' => $closeout?->parts->map(fn ($part): array => [
                        'description' => $part->description,
                        'quantity' => (string) $part->quantity,
                        'unit' => $part->unit,
                    ])->values()->all() ?? [],
                    'acknowledgment' => $this->acknowledgment($closeout),
                ];
            })->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function acknowledgment($closeout): array
    {
        if (! $closeout) {
            return ['type' => 'none'];
        }
        if ($closeout->acknowledgmentSignature) {
            $signature = $closeout->acknowledgmentSignature;

            return [
                'type' => 'signed',
                'name' => $signature->signer_name,
                'role' => $signature->signer_role,
                'occurred_at' => $signature->signed_at?->toIso8601String(),
                'statement_version' => $signature->statement_version,
            ];
        }
        if ($closeout->ack_unavailable_category) {
            return [
                'type' => 'fallback',
                'name' => $closeout->representative_name,
                'role' => $closeout->representative_role,
                'category' => (string) config('field_execution.ack_fallbacks.'.$closeout->ack_unavailable_category, str($closeout->ack_unavailable_category)->headline()),
                'detail' => $closeout->ack_unavailable_detail,
                'occurred_at' => $closeout->acknowledged_at?->toIso8601String(),
            ];
        }

        return ['type' => 'none'];
    }

    private function workItemStatus(string $status): string
    {
        return match ($status) {
            'needs_follow_up' => 'Follow-up required',
            'transferred' => 'Transferred to follow-up',
            default => str($status)->headline()->toString(),
        };
    }

    private function ticketStatus(string $status): string
    {
        return match ($status) {
            'on_hold' => 'On hold',
            'completed' => 'Completed',
            'canceled' => 'Canceled',
            default => 'Open',
        };
    }
}
