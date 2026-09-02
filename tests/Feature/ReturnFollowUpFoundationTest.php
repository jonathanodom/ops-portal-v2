<?php

namespace Tests\Feature;

use App\Domain\ReturnFollowUpStatus;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReturnFollowUpFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_tickets_remain_valid_without_return_follow_up_provenance(): void
    {
        $ticket = $this->ticketGraph()['ticket'];

        $this->assertFalse($ticket->isReturnFollowUp());
        $this->assertNull($ticket->returnFollowUpSourceTicket);
        $this->assertNull($ticket->returnFollowUpSourceCloseout);
        $this->assertCount(0, $ticket->returnFollowUpTickets);
        $this->assertTrue(Schema::hasColumns('service_tickets', [
            'return_follow_up_source_ticket_id',
            'return_follow_up_source_closeout_id',
            'return_follow_up_original_purpose',
            'return_follow_up_status',
        ]));
    }

    public function test_follow_up_ticket_retains_structured_source_relationship_and_closeout_context(): void
    {
        ['ticket' => $source, 'closeout' => $closeout, 'technician' => $technician] = $this->ticketGraph();

        $followUp = ServiceTicket::query()->create([
            'organization_id' => $source->organization_id,
            'customer_id' => $source->customer_id,
            'service_location_id' => $source->service_location_id,
            'ticket_number' => 'NDT-ST-2026-9002',
            'title' => 'Return Visit — Purpose-aware closeout',
            'priority' => 'normal',
            'source' => 'internal',
            'purpose' => 'installation_project',
            'status' => 'open',
            'return_follow_up_source_ticket_id' => $source->id,
            'return_follow_up_source_closeout_id' => $closeout->id,
            'return_follow_up_original_purpose' => 'installation_project',
            'return_follow_up_status' => ReturnFollowUpStatus::NEEDS_REVIEW,
        ]);

        $this->assertTrue($followUp->isReturnFollowUp());
        $this->assertTrue($followUp->returnFollowUpSourceTicket->is($source));
        $this->assertTrue($followUp->returnFollowUpSourceCloseout->is($closeout));
        $this->assertTrue($source->fresh()->returnFollowUpTickets->first()->is($followUp));
        $this->assertSame('Lift required', $followUp->returnFollowUpSourceCloseout->return_reason);
        $this->assertSame('Install final camera', $followUp->returnFollowUpSourceCloseout->unfinished_work);
        $this->assertSame('35-foot lift', $followUp->returnFollowUpSourceCloseout->needed_equipment);
        $this->assertTrue($followUp->returnFollowUpSourceCloseout->submittedBy->is($technician));
        $this->assertSame('2026-09-01 15:30:00', $followUp->returnFollowUpSourceCloseout->submitted_at->format('Y-m-d H:i:s'));
        $this->assertSame(ReturnFollowUpStatus::values(), [
            'needs_review', 'waiting_on_parts', 'ready_to_schedule', 'scheduled', 'completed', 'canceled',
        ]);
    }

    public function test_one_closeout_cannot_source_duplicate_follow_up_tickets(): void
    {
        ['ticket' => $source, 'closeout' => $closeout] = $this->ticketGraph();
        $attributes = [
            'organization_id' => $source->organization_id,
            'customer_id' => $source->customer_id,
            'service_location_id' => $source->service_location_id,
            'title' => 'Return Visit — Purpose-aware closeout',
            'priority' => 'normal',
            'source' => 'internal',
            'purpose' => 'installation_project',
            'status' => 'open',
            'return_follow_up_source_ticket_id' => $source->id,
            'return_follow_up_source_closeout_id' => $closeout->id,
            'return_follow_up_original_purpose' => 'installation_project',
            'return_follow_up_status' => ReturnFollowUpStatus::NEEDS_REVIEW,
        ];

        ServiceTicket::query()->create($attributes + ['ticket_number' => 'NDT-ST-2026-9003']);

        $this->expectException(QueryException::class);
        ServiceTicket::query()->create($attributes + ['ticket_number' => 'NDT-ST-2026-9004']);
    }

    /** @return array{ticket: ServiceTicket, closeout: Closeout, technician: User} */
    private function ticketGraph(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $technician = User::factory()->create();
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Return Test Site',
            'address_line_1' => '100 Main Street',
            'city' => 'Fort Worth',
            'state' => 'TX',
            'postal_code' => '76102',
            'timezone' => 'America/Chicago',
            'is_primary' => true,
            'active' => true,
        ]);
        $ticket = ServiceTicket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => 'NDT-ST-2026-9001',
            'title' => 'Purpose-aware closeout',
            'priority' => 'normal',
            'source' => 'internal',
            'purpose' => 'installation_project',
            'status' => 'open',
        ]);
        $visit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id,
            'status' => 'pending_closeout',
            'timezone' => 'America/Chicago',
        ]);
        $closeout = Closeout::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $visit->id,
            'version' => 1,
            'status' => 'submitted',
            'content_version' => 1,
            'outcome' => 'needs_return_trip',
            'work_performed' => 'Installed NVR and six cameras',
            'return_reason' => 'Lift required',
            'unfinished_work' => 'Install final camera',
            'needed_equipment' => '35-foot lift',
            'submitted_by_id' => $technician->id,
            'submitted_at' => '2026-09-01 15:30:00',
        ]);

        return compact('ticket', 'closeout', 'technician');
    }
}
