<?php

namespace Tests\Feature;

use App\Domain\CloseoutReadiness;
use App\Domain\ServiceTicketPurpose;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurposeAwareCloseoutFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_supported_catalog_contains_exactly_five_purposes_and_preserves_callback_as_legacy(): void
    {
        $this->assertSame([
            'site_survey' => 'Site / Survey Visit',
            'installation_project' => 'Installation / Project',
            'service_call' => 'Service Visit',
            'warranty' => 'Warranty / Maintenance Visit',
            'internal_test' => 'Internal / Testing',
        ], ServiceTicketPurpose::supported());
        $this->assertSame('service_call', ServiceTicketPurpose::canonical('callback'));
        $this->assertSame('Callback / Return Visit (legacy)', ServiceTicketPurpose::label('callback'));
        $this->assertArrayHasKey('callback', ServiceTicketPurpose::selectable('callback'));
        $this->assertArrayNotHasKey('callback', ServiceTicketPurpose::selectable());

        $closeout = $this->closeout('callback', 'resolved', ['work_performed' => 'Legacy callback work']);
        $this->assertSame('callback', $closeout->visit->serviceTicket->purpose);
        $this->assertSame('Service Visit', ServiceTicketPurpose::label($closeout->visit->serviceTicket->canonicalPurpose()));
        $this->assertArrayHasKey('diagnosis', app(CloseoutReadiness::class)->errors($closeout));
    }

    public function test_site_installation_and_warranty_require_work_but_not_diagnosis(): void
    {
        foreach (['site_survey', 'installation_project', 'warranty'] as $purpose) {
            $complete = $this->closeout($purpose, 'resolved', ['work_performed' => 'Purpose-appropriate summary']);
            $errors = app(CloseoutReadiness::class)->errors($complete);
            $this->assertArrayNotHasKey('diagnosis', $errors, $purpose);
            $this->assertArrayNotHasKey('work_performed', $errors, $purpose);

            $missingWork = $this->closeout($purpose, 'resolved');
            $this->assertArrayHasKey('work_performed', app(CloseoutReadiness::class)->errors($missingWork), $purpose);
        }
    }

    public function test_internal_testing_requires_work_and_result_but_not_diagnosis_or_acknowledgment(): void
    {
        $complete = $this->closeout('internal_test', 'resolved', [
            'work_performed' => 'Bench-tested access point firmware',
            'result_summary' => 'Passed stability testing',
            'ack_unavailable_category' => null,
            'ack_unavailable_detail' => null,
        ]);
        $errors = app(CloseoutReadiness::class)->errors($complete, false, true);
        $this->assertArrayNotHasKey('diagnosis', $errors);
        $this->assertArrayNotHasKey('representative_name', $errors);
        $this->assertArrayNotHasKey('signature_data', $errors);

        $missingWork = $this->closeout('internal_test', 'resolved', ['result_summary' => 'Failed']);
        $this->assertArrayHasKey('work_performed', app(CloseoutReadiness::class)->errors($missingWork));

        $missingResult = $this->closeout('internal_test', 'resolved', ['work_performed' => 'Ran test']);
        $this->assertSame(
            'Result / Outcome is required for Internal / Testing.',
            app(CloseoutReadiness::class)->errors($missingResult)['result_summary'],
        );
    }

    public function test_service_visits_require_diagnosis_and_work_performed(): void
    {
        $missingDiagnosis = $this->closeout('service_call', 'resolved', ['work_performed' => 'Replaced failed extender']);
        $errors = app(CloseoutReadiness::class)->errors($missingDiagnosis);
        $this->assertSame('Diagnosis / Root Cause is required for a Service Visit.', $errors['diagnosis']);
        $this->assertArrayNotHasKey('work_performed', $errors);

        $complete = $this->closeout('service_call', 'resolved', [
            'diagnosis' => 'HDMI extender receiver failed',
            'work_performed' => 'Replaced extender and verified signal',
        ]);
        $errors = app(CloseoutReadiness::class)->errors($complete);
        $this->assertArrayNotHasKey('diagnosis', $errors);
        $this->assertArrayNotHasKey('work_performed', $errors);
    }

    public function test_return_trip_requires_only_a_return_reason_beyond_purpose_narrative(): void
    {
        $missingReason = $this->closeout('installation_project', 'needs_return_trip', [
            'work_performed' => 'Installed NVR and six cameras',
        ]);
        $errors = app(CloseoutReadiness::class)->errors($missingReason);
        $this->assertArrayHasKey('return_reason', $errors);
        $this->assertArrayNotHasKey('diagnosis', $errors);
        $this->assertArrayNotHasKey('unfinished_work', $errors);
        $this->assertArrayNotHasKey('needed_equipment', $errors);
        $this->assertArrayNotHasKey('recommendations', $errors);

        $complete = $this->closeout('installation_project', 'needs_return_trip', [
            'work_performed' => 'Installed NVR and six cameras',
            'return_reason' => 'Additional camera requires lift access',
        ]);
        $this->assertArrayNotHasKey('return_reason', app(CloseoutReadiness::class)->errors($complete));
    }

    public function test_validation_uses_the_current_purpose_and_ignores_stale_return_fields(): void
    {
        $closeout = $this->closeout('service_call', 'needs_return_trip', [
            'diagnosis' => 'Entered while this was a Service Visit',
            'work_performed' => 'Initial work',
            'return_reason' => 'Initial return reason',
            'unfinished_work' => 'Stale unfinished work',
            'needed_equipment' => 'Stale equipment note',
        ]);
        $this->assertArrayNotHasKey('return_reason', app(CloseoutReadiness::class)->errors($closeout));

        $closeout->visit->serviceTicket->update(['purpose' => 'installation_project']);
        $closeout->update(['outcome' => 'resolved', 'diagnosis' => null]);
        $errors = app(CloseoutReadiness::class)->errors($closeout->fresh());
        $this->assertArrayNotHasKey('diagnosis', $errors);
        $this->assertArrayNotHasKey('return_reason', $errors);
        $this->assertArrayNotHasKey('unfinished_work', $errors);
        $this->assertArrayNotHasKey('needed_equipment', $errors);

        $closeout->visit->serviceTicket->update(['purpose' => 'service_call']);
        $this->assertArrayHasKey('diagnosis', app(CloseoutReadiness::class)->errors($closeout->fresh()));

        $closeout->visit->serviceTicket->update(['purpose' => 'site_survey']);
        $this->assertArrayNotHasKey('diagnosis', app(CloseoutReadiness::class)->errors($closeout->fresh()));
    }

    private function closeout(string $purpose, string $outcome, array $attributes = []): Closeout
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Purpose Test Site',
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
            'ticket_number' => 'NDT-ST-2026-'.str_pad((string) $organization->id, 4, '0', STR_PAD_LEFT),
            'title' => 'Purpose-aware closeout',
            'priority' => 'normal',
            'source' => 'internal',
            'purpose' => $purpose,
            'status' => 'open',
        ]);
        $visit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id,
            'status' => 'on_site',
            'timezone' => 'America/Chicago',
        ]);

        return Closeout::query()->create(array_merge([
            'organization_id' => $organization->id,
            'visit_id' => $visit->id,
            'version' => 1,
            'status' => 'draft',
            'content_version' => 1,
            'outcome' => $outcome,
            'ack_unavailable_category' => 'remote_service',
            'ack_unavailable_detail' => 'Confirmed remotely',
            'no_photo_category' => 'not_applicable',
            'no_photo_detail' => 'No visual evidence applicable',
        ], $attributes));
    }
}
