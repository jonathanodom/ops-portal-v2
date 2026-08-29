<?php

namespace App\Support\Api\V1;

use App\Models\ServiceTicket;

/**
 * Shapes a ServiceTicket for /api/v1, per
 * docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md §8.3.
 *
 * External field name `location_id` maps to the internal
 * `service_location_id` column; see App\Http\Controllers\Api\V1\TicketController.
 */
final class TicketSummary
{
    /** @return array<string, mixed> */
    public static function make(ServiceTicket $ticket): array
    {
        return [
            'id' => (string) $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'customer_id' => (string) $ticket->customer_id,
            'location_id' => (string) $ticket->service_location_id,
            'contact_id' => $ticket->contact_id !== null ? (string) $ticket->contact_id : null,
            'title' => $ticket->title,
            'description' => $ticket->description,
            'customer_visible_summary' => $ticket->customer_visible_summary,
            'priority' => $ticket->priority,
            'source' => $ticket->source,
            'purpose' => $ticket->purpose,
            'billing_disposition' => $ticket->billing_disposition,
            'status' => $ticket->status,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];
    }
}
