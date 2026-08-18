<?php

namespace App\Support\LocalExamples;

use App\Domain\CustomerCreationWorkflow;
use App\Domain\CustomerServiceEnrollmentWorkflow;
use App\Domain\InvoiceWorkflow;
use App\Domain\PaymentWorkflow;
use App\Domain\Projects\Actions\ProjectWorkflow;
use App\Domain\Projects\Contracts\ServiceOperationsDirectory;
use App\Domain\VisitCreator;
use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\CloseoutReview;
use App\Models\Customer;
use App\Models\OperationalIncident;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketFile;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Models\VisitMedia;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use App\Support\ServiceTicketNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class LocalExampleBootstrapper
{
    public function __construct(
        private readonly LocalExampleGuard $guard,
        private readonly LocalExampleInventory $inventory,
        private readonly LocalExampleCatalog $catalog,
        private readonly CustomerCreationWorkflow $customers,
        private readonly CustomerServiceEnrollmentWorkflow $enrollments,
        private readonly VisitCreator $visits,
        private readonly ServiceTicketNumber $ticketNumbers,
        private readonly ProjectWorkflow $projects,
        private readonly ServiceOperationsDirectory $operations,
        private readonly InvoiceWorkflow $invoices,
        private readonly PaymentWorkflow $payments,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string, int> */
    public function bootstrap(Organization $organization, string $profile): array
    {
        $profile = $this->guard->profile($profile);
        $membership = $this->guard->superAdmin($organization);
        $actor = $membership->user;
        $status = $this->inventory->status($organization, $profile);
        if ($status === 'complete') {
            return $profile === 'full' ? LocalExampleInventory::FULL_EXPECTED : LocalExampleInventory::SMALL_EXPECTED;
        }
        if ($status !== 'absent') {
            throw new RuntimeException('Examples already exist. Inventory the database and use the guarded reset if needed.');
        }

        Queue::fake();
        DB::transaction(function () use ($organization, $membership, $actor, $profile): void {
            $catalog = $this->catalog->create($organization, $actor);
            $context = $this->createSmallProfile($organization, $membership, $actor, $catalog);
            if ($profile === 'full') {
                $this->createVolumeProfile($organization, $membership, $actor, $context);
            }
            $this->audit->record($organization, $actor, 'local_examples.bootstrapped', $organization, [
                'profile' => $profile,
                'changed_fields' => ['customers', 'locations', 'tickets', 'visits', 'closeouts', 'invoices', 'projects', 'catalog'],
            ]);
        });

        $expected = $profile === 'full' ? LocalExampleInventory::FULL_EXPECTED : LocalExampleInventory::SMALL_EXPECTED;
        if ($this->inventory->status($organization, $profile) !== 'complete') {
            throw new RuntimeException('The example profile did not satisfy its deterministic count contract. Reset from the verified backup.');
        }

        return $expected;
    }

    /** @return array{customers: array<int, Customer>, locations: array<int, ServiceLocation>, tickets: array<int, ServiceTicket>, visits: array<int, Visit>} */
    private function createSmallProfile(Organization $organization, OrganizationMembership $membership, User $actor, array $catalog): array
    {
        $today = CarbonImmutable::today($organization->timezone);
        $customers = [];
        $locations = [];
        $customerSpecs = [
            ['EXAMPLE — Acme Dental Group', 'business', 'active', 'Morgan Lee'],
            ['EXAMPLE — Harper Residence', 'individual', 'active', 'Jordan Harper'],
            ['EXAMPLE — Grace Community Church', 'church_nonprofit', 'active', 'Avery Brooks'],
            ['EXAMPLE — Lakeview Offices', 'business', 'on_hold', 'Riley Carter'],
            ['EXAMPLE — Northside Café', 'business', 'active', 'Casey Morgan'],
            ['EXAMPLE — Pine Street Rentals', 'business', 'active', 'Taylor Reed'],
            ['EXAMPLE — Retired Customer', 'individual', 'inactive', 'Jamie Quinn'],
            ['EXAMPLE — Community Arts Center', 'church_nonprofit', 'active', 'Parker Ellis'],
        ];
        foreach ($customerSpecs as $index => [$name, $type, $status, $contact]) {
            $created = $this->customers->create($organization, $actor, [
                'type' => $type, 'display_name' => $name, 'legal_name' => $type === 'business' ? str_replace('EXAMPLE — ', '', $name).' LLC' : null,
                'phone' => sprintf('(940) 555-%04d', 1100 + $index), 'email' => "example.customer{$index}@newday.test",
                'status' => $status, 'notes' => 'Synthetic local example; safe to remove with examples:reset.',
                'contact' => ['name' => $contact, 'role' => 'Point of contact', 'phone' => sprintf('(940) 555-%04d', 2100 + $index), 'email' => "example.contact{$index}@newday.test"],
                'location' => [
                    'name' => "EXAMPLE — {$contact} Primary Site", 'address_line_1' => (100 + $index).' Example Lane',
                    'city' => 'Bryson', 'state' => 'TX', 'postal_code' => '76427', 'timezone' => $organization->timezone,
                    'access_instructions' => 'Use the marked example entrance.', 'site_notes' => 'Synthetic office-only site note.',
                ],
            ], $this->audit);
            $customers[] = $created['customer'];
            $locations[] = $created['location'];
        }
        foreach ([0, 2] as $offset) {
            $primary = $locations[$offset];
            $locations[] = ServiceLocation::query()->create([
                'organization_id' => $organization->id, 'customer_id' => $customers[$offset]->id,
                'primary_contact_id' => $primary->primary_contact_id, 'name' => 'EXAMPLE — Secondary Service Site '.($offset + 1),
                'address_line_1' => (300 + $offset).' Example Avenue', 'city' => 'Graham', 'state' => 'TX', 'postal_code' => '76450',
                'timezone' => $organization->timezone, 'access_instructions' => 'Call before arrival.', 'site_notes' => 'Secondary synthetic site.',
                'is_primary' => false, 'active' => true, 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
        }

        $this->enrollments->create($customers[0], $actor, [
            'service_location_id' => $locations[0]->id, 'catalog_service_id' => $catalog['managedService']->id,
            'start_date' => $today->subMonth()->toDateString(), 'next_billing_date' => $today->addMonth()->toDateString(),
            'internal_notes' => 'Synthetic active recurring Service.',
        ]);
        $paused = $this->enrollments->create($customers[2], $actor, [
            'service_location_id' => $locations[2]->id, 'catalog_service_id' => $catalog['managedService']->id,
            'start_date' => $today->subMonths(2)->toDateString(), 'next_billing_date' => $today->addMonth()->toDateString(),
            'internal_notes' => 'Synthetic paused recurring Service.',
        ]);
        $this->enrollments->transition($paused, $actor, 'paused');

        $ticketSpecs = [
            ['EXAMPLE — Assigned today: workstation outage', 'open', 'urgent', 0, 'assigned'],
            ['EXAMPLE — Office manual closeout: network repair', 'open', 'high', 1, 'assigned'],
            ['EXAMPLE — Unscheduled backlog: conference display', 'open', 'normal', 2, 'planned'],
            ['EXAMPLE — Scheduled, awaiting assignment', 'open', 'normal', 3, 'scheduled'],
            ['EXAMPLE — Ticket on hold: replacement equipment', 'on_hold', 'high', 4, 'assigned'],
            ['EXAMPLE — Canceled with auto-stopped travel', 'canceled', 'normal', 5, 'canceled'],
            ['EXAMPLE — Return trip required: intermittent cabling', 'open', 'high', 0, 'return_trip'],
            ['EXAMPLE — Customer unavailable disposition', 'open', 'normal', 1, 'customer_unavailable'],
            ['EXAMPLE — Closeout pending office review', 'open', 'normal', 2, 'pending_closeout'],
            ['EXAMPLE — Returned for correction', 'open', 'high', 3, 'returned_for_correction'],
            ['EXAMPLE — Approved and completed service', 'completed', 'normal', 4, 'approved'],
            ['EXAMPLE — Archived accidental Visit', 'open', 'low', 5, 'archived'],
        ];
        $tickets = [];
        $visits = [];
        $closeouts = [];
        foreach ($ticketSpecs as $index => [$title, $ticketStatus, $priority, $customerIndex, $scenario]) {
            $customer = $customers[$customerIndex];
            $location = $locations[$customerIndex];
            $ticket = ServiceTicket::query()->create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id,
                'contact_id' => $location->primary_contact_id, 'ticket_number' => $this->ticketNumbers->next($organization),
                'title' => $title, 'description' => 'Synthetic local workflow example.',
                'customer_visible_summary' => 'Example service workflow for local testing.', 'priority' => $priority,
                'source' => 'internal', 'status' => $ticketStatus, 'purpose' => 'service_call', 'billing_disposition' => 'billable',
                'status_changed_at' => now(), 'status_changed_by_id' => $actor->id,
                'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            $tickets[] = $ticket;
            $windowDay = match ($scenario) {
                'assigned' => $index === 0 ? 0 : 1,
                'scheduled' => 2,
                'return_trip' => -2,
                'customer_unavailable' => -3,
                'pending_closeout' => -1,
                'returned_for_correction' => -4,
                'approved' => -5,
                'canceled' => -1,
                'archived' => 3,
                default => null,
            };
            $status = in_array($scenario, ['return_trip', 'customer_unavailable', 'pending_closeout', 'returned_for_correction', 'approved'], true) ? 'assigned' : $scenario;
            if ($scenario === 'assigned' && $ticketStatus === 'on_hold') {
                $status = 'assigned';
            }
            $visit = $this->visits->create($ticket, [
                'service_location_id' => $location->id, 'status' => $status, 'timezone' => $location->timezone,
                'scheduled_start_at' => $windowDay === null ? null : $today->addDays($windowDay)->setTime(9 + ($index % 6), 0)->utc(),
                'scheduled_end_at' => $windowDay === null ? null : $today->addDays($windowDay)->setTime(10 + ($index % 6), 0)->utc(),
                'scheduled_by_id' => $actor->id, 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            $visits[] = $visit;
            if (! in_array($scenario, ['planned', 'scheduled', 'archived'], true)) {
                VisitAssignment::query()->create([
                    'organization_id' => $organization->id, 'visit_id' => $visit->id,
                    'organization_membership_id' => $membership->id, 'is_lead' => true, 'assigned_by_id' => $actor->id,
                ]);
            }

            if ($ticketStatus === 'on_hold') {
                $closeout = $this->submittedCloseout($organization, $visit, $actor, 'on_hold', 1);
                $visit->update(['status' => 'pending_closeout']);
                $closeouts[] = $closeout;
            }
            if ($scenario === 'canceled') {
                $canceledCloseout = Closeout::query()->create([
                    'organization_id' => $organization->id,
                    'visit_id' => $visit->id,
                    'version' => 1,
                    'status' => 'draft',
                    'content_version' => 1,
                    'last_saved_by_id' => $actor->id,
                ]);
                $visit->update(['current_closeout_id' => $canceledCloseout->id]);
                $closeouts[] = $canceledCloseout;
                $visit->update(['status' => 'canceled', 'en_route_at' => $today->subDay()->setTime(8, 0)->utc(), 'en_route_by_id' => $actor->id, 'canceled_at' => $today->subDay()->setTime(8, 35)->utc(), 'canceled_by_id' => $actor->id, 'cancellation_reason' => 'Synthetic cancellation.']);
                VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $canceledCloseout->id, 'user_id' => $actor->id, 'category' => 'travel', 'started_at' => $today->subDay()->setTime(8, 0)->utc(), 'ended_at' => $today->subDay()->setTime(8, 35)->utc(), 'source' => 'system_auto']);
            }
            if ($scenario === 'archived') {
                $visit->update(['archived_by_id' => $actor->id, 'archive_reason' => 'Synthetic accidental Visit.']);
                $visit->delete();
            }
            if ($scenario === 'return_trip') {
                $first = $this->submittedCloseout($organization, $visit, $actor, 'needs_return_trip', 1);
                $return = $this->visits->create($ticket, ['service_location_id' => $location->id, 'return_of_visit_id' => $visit->id, 'status' => 'planned', 'timezone' => $location->timezone, 'return_reason' => 'Synthetic return-trip requirement.', 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id]);
                $visit->update(['status' => 'approved']);
                $first->update(['return_visit_id' => $return->id]);
                CloseoutReview::query()->create(['organization_id' => $organization->id, 'closeout_id' => $first->id, 'reviewer_id' => $actor->id, 'decision' => 'approved', 'self_review_override' => true, 'decision_token' => (string) Str::uuid(), 'decided_at' => now()]);
                $visits[] = $return;
                $closeouts[] = $first;
            } elseif ($scenario === 'customer_unavailable') {
                $closeout = $this->submittedCloseout($organization, $visit, $actor, 'customer_unavailable', 1);
                $visit->update(['status' => 'customer_unavailable']);
                $closeouts[] = $closeout;
            } elseif ($scenario === 'pending_closeout') {
                $closeout = $this->submittedCloseout($organization, $visit, $actor, 'resolved', 1);
                $visit->update(['status' => 'pending_closeout']);
                $closeouts[] = $closeout;
            } elseif ($scenario === 'returned_for_correction') {
                $parent = $this->submittedCloseout($organization, $visit, $actor, 'resolved', 1);
                CloseoutReview::query()->create(['organization_id' => $organization->id, 'closeout_id' => $parent->id, 'reviewer_id' => $actor->id, 'decision' => 'returned', 'reason' => 'Add the missing outcome detail.', 'self_review_override' => true, 'decision_token' => (string) Str::uuid(), 'decided_at' => now()]);
                $child = Closeout::query()->create($parent->only(['organization_id', 'visit_id', 'outcome', 'diagnosis', 'work_performed', 'recommendations', 'representative_name']) + ['parent_closeout_id' => $parent->id, 'version' => 2, 'status' => 'draft', 'content_version' => 1, 'last_saved_by_id' => $actor->id]);
                $visit->update(['status' => 'returned_for_correction', 'current_closeout_id' => $child->id]);
                $closeouts[] = $parent;
                $closeouts[] = $child;
            } elseif ($scenario === 'approved') {
                $closeout = $this->submittedCloseout($organization, $visit, $actor, 'resolved', 1);
                CloseoutReview::query()->create(['organization_id' => $organization->id, 'closeout_id' => $closeout->id, 'reviewer_id' => $actor->id, 'decision' => 'approved', 'self_review_override' => true, 'decision_token' => (string) Str::uuid(), 'decided_at' => now()]);
                $visit->update(['status' => 'approved']);
                BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'ready', 'approved_time_minutes' => 60, 'approved_parts_count' => 0, 'created_by_id' => $actor->id]);
                $closeouts[] = $closeout;
            }
        }

        $this->createMedia($organization, $actor, $closeouts);
        $this->createTicketFiles($organization, $actor, $tickets[0]);
        $this->createProjects($organization, $actor, $customers, $locations, $tickets, $today);
        $this->createInvoices($organization, $actor, $customers[0], $locations[0]);
        $this->createIncidents($organization, $actor, $tickets[0]);

        return compact('customers', 'locations', 'tickets', 'visits');
    }

    private function submittedCloseout(Organization $organization, Visit $visit, User $actor, string $outcome, int $version): Closeout
    {
        $values = [
            'organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => $version,
            'status' => 'submitted', 'content_version' => 2, 'outcome' => $outcome,
            'diagnosis' => 'Synthetic diagnosis for local workflow testing.', 'work_performed' => 'Synthetic work performed.',
            'recommendations' => 'Review the example workflow.', 'representative_name' => 'Example Point of Contact',
            'acknowledged_at' => now(), 'submitted_token' => (string) Str::uuid(), 'submitted_by_id' => $actor->id,
            'submitted_at' => now(), 'last_saved_by_id' => $actor->id,
        ];
        if ($outcome === 'needs_return_trip') {
            $values += ['return_reason' => 'Additional equipment is required.', 'unfinished_work' => 'Complete final termination.', 'needed_equipment' => 'Replacement module.'];
        }
        if ($outcome === 'customer_unavailable') {
            $values += ['unavailable_category' => 'representative_unavailable', 'unavailable_detail' => 'No authorized representative was available.', 'representative_name' => null, 'acknowledged_at' => null];
        }
        if ($outcome === 'on_hold') {
            $values += ['hold_reason' => 'Synthetic equipment dependency.'];
        }
        $closeout = Closeout::query()->create($values);
        $visit->update(['current_closeout_id' => $closeout->id]);

        return $closeout;
    }

    private function createMedia(Organization $organization, User $actor, array $closeouts): void
    {
        $disk = (string) config('filesystems.default', 'local');
        foreach (array_slice($closeouts, 0, 5) as $index => $closeout) {
            $key = "local-examples/visits/{$closeout->visit_id}/".Str::uuid().'.png';
            Storage::disk($disk)->put($key, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
            VisitMedia::query()->create([
                'organization_id' => $organization->id, 'visit_id' => $closeout->visit_id, 'closeout_id' => $closeout->id,
                'uploader_id' => $actor->id, 'storage_disk' => $disk, 'storage_key' => $key, 'mime_type' => 'image/png',
                'byte_size' => 68, 'category' => $index % 2 === 0 ? 'after' : 'before', 'caption' => 'Synthetic example evidence.', 'state' => 'stored',
            ]);
        }
    }

    private function createTicketFiles(Organization $organization, User $actor, ServiceTicket $ticket): void
    {
        $disk = (string) config('service_ticket_files.disk', 'local');
        foreach ([['site-reference.pdf', 'application/pdf', "%PDF-1.4\n% Synthetic local example\n%%EOF"], ['rack-reference.png', 'image/png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')]] as $index => [$name, $mime, $contents]) {
            $key = 'local-examples/tickets/'.Str::uuid();
            Storage::disk($disk)->put($key, $contents);
            ServiceTicketFile::query()->create([
                'organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'uploaded_by_id' => $actor->id,
                'storage_disk' => $disk, 'storage_key' => $key, 'original_name' => "EXAMPLE-{$name}", 'mime_type' => $mime,
                'byte_size' => strlen($contents), 'caption' => $index === 0 ? 'Synthetic scope reference.' : 'Synthetic rack reference.', 'state' => 'stored',
            ]);
        }
    }

    private function createProjects(Organization $organization, User $actor, array $customers, array $locations, array $tickets, CarbonImmutable $today): void
    {
        $installation = $this->projects->create($organization, $actor, [
            'name' => 'EXAMPLE — Acme Dental Network & AV Upgrade', 'type' => 'installation_project', 'status' => 'active',
            'customer_id' => $customers[0]->id, 'service_location_id' => $locations[0]->id, 'owner_user_id' => $actor->id,
            'start_on' => $today->toDateString(), 'target_end_on' => $today->addMonths(2)->toDateString(),
            'summary' => 'Synthetic finite installation Project.', 'objective' => 'Validate complete Projects workflows.',
        ]);
        $design = $this->projects->addWorkstream($installation, $actor, ['name' => 'Design', 'status' => 'active', 'sort_order' => 10]);
        $this->projects->addWorkstream($installation, $actor, ['name' => 'Installation', 'status' => 'planned', 'sort_order' => 20]);
        $this->projects->addTask($installation, $actor, ['workstream_id' => $design->id, 'title' => 'EXAMPLE — Complete site design', 'status' => 'in_progress', 'priority' => 'high', 'assigned_to_user_id' => $actor->id, 'due_on' => $today->addDays(3)->toDateString()]);
        $this->projects->addTask($installation, $actor, ['workstream_id' => $design->id, 'title' => 'EXAMPLE — Resolve vendor dependency', 'status' => 'blocked', 'priority' => 'urgent', 'assigned_to_user_id' => $actor->id, 'due_on' => $today->subDay()->toDateString(), 'blocked_reason' => 'Synthetic vendor dependency.']);
        $this->projects->addMilestone($installation, $actor, ['name' => 'EXAMPLE — Design approved', 'status' => 'planned', 'target_on' => $today->addWeeks(2)->toDateString()]);
        $this->projects->addNote($installation, $actor, ['type' => 'customer_update', 'body' => 'Synthetic Project note for local testing.']);
        $this->projects->linkTicket($installation, $this->operations->resolve($organization, $tickets[0]->id), $actor, false);

        $support = $this->projects->create($organization, $actor, [
            'name' => 'EXAMPLE — Community Arts Ongoing Support', 'type' => 'ongoing_support', 'status' => 'active',
            'customer_id' => $customers[7]->id, 'owner_user_id' => $actor->id,
            'start_on' => $today->subMonth()->toDateString(), 'summary' => 'Synthetic indefinite support Project.',
        ]);
        $operations = $this->projects->addWorkstream($support, $actor, ['name' => 'Operations', 'status' => 'active', 'sort_order' => 10]);
        $this->projects->addTask($support, $actor, ['workstream_id' => $operations->id, 'title' => 'EXAMPLE — Quarterly review', 'status' => 'planned', 'priority' => 'normal', 'assigned_to_user_id' => $actor->id, 'due_on' => $today->addWeek()->toDateString()]);
        $this->projects->addTask($support, $actor, ['workstream_id' => $operations->id, 'title' => 'EXAMPLE — Document completed change', 'status' => 'done', 'priority' => 'low', 'assigned_to_user_id' => $actor->id, 'due_on' => $today->subDays(2)->toDateString()]);
        $this->projects->addMilestone($support, $actor, ['name' => 'EXAMPLE — Quarterly review', 'status' => 'in_progress', 'target_on' => $today->addWeeks(3)->toDateString()]);
    }

    private function createInvoices(Organization $organization, User $actor, Customer $customer, ServiceLocation $location): void
    {
        $create = function (string $label, int $amount) use ($organization, $actor, $customer, $location) {
            $invoice = $this->invoices->createDirect($organization, $customer->id, $location->id, $location->primary_contact_id, $actor, (string) Str::uuid());
            $this->invoices->addLine($invoice, $actor, ['line_type' => 'service_charge', 'description' => "EXAMPLE — {$label}", 'quantity_millis' => 1000, 'unit' => 'service', 'unit_price_cents' => $amount, 'included' => true, 'taxable' => false, 'override_reason' => 'Synthetic local example.']);

            return $invoice->fresh();
        };

        $create('Draft invoice', 12500);
        $ready = $create('Ready-for-review invoice', 15000);
        $this->invoices->markReady($ready, $actor);
        $unpaid = $create('Issued unpaid invoice', 17500);
        $this->invoices->issue($this->invoices->markReady($unpaid, $actor), $actor, (string) Str::uuid());
        $partial = $create('Partially paid cash invoice', 20000);
        $partial = $this->invoices->issue($this->invoices->markReady($partial, $actor), $actor, (string) Str::uuid());
        $this->payments->recordManual($partial, $actor, 'cash', 7500, now(), null, (string) Str::uuid(), 'Synthetic partial payment.');
        $check = $create('Paid check invoice', 22500);
        $check = $this->invoices->issue($this->invoices->markReady($check, $actor), $actor, (string) Str::uuid());
        $this->payments->recordManual($check, $actor, 'check', 22500, now(), 'EXAMPLE-CHECK-1001', (string) Str::uuid(), 'Synthetic check payment.');
        $zero = $create('Zero-dollar warranty invoice', 0);
        $this->invoices->issue($this->invoices->markReady($zero, $actor), $actor, (string) Str::uuid());
        $void = $create('Void and reissue example', 25000);
        $void = $this->invoices->issue($this->invoices->markReady($void, $actor), $actor, (string) Str::uuid());
        $this->invoices->voidAndReissue($void, $actor, 'Synthetic correction example.', (string) Str::uuid());
        $paid = $create('Paid cash invoice', 10000);
        $paid = $this->invoices->issue($this->invoices->markReady($paid, $actor), $actor, (string) Str::uuid());
        $this->payments->recordManual($paid, $actor, 'cash', 10000, now(), null, (string) Str::uuid(), 'Synthetic full payment.');
    }

    private function createIncidents(Organization $organization, User $actor, ServiceTicket $ticket): void
    {
        foreach ([['warning', 'open'], ['info', 'resolved']] as $index => [$severity, $status]) {
            OperationalIncident::query()->create([
                'organization_id' => $organization->id, 'category' => $index === 0 ? 'stuck_state' : 'example_recovery',
                'severity' => $severity, 'fingerprint' => hash('sha256', "local-example-{$organization->id}-{$index}"),
                'subject_type' => $ticket->getMorphClass(), 'subject_id' => (string) $ticket->id, 'actor_id' => $actor->id,
                'request_id' => (string) Str::uuid(), 'context' => ['ticket_id' => $ticket->id, 'state' => 'example'],
                'status' => $status, 'occurrences' => $index + 1, 'first_occurred_at' => now()->subHours(2), 'last_occurred_at' => now(),
                'resolved_by_id' => $status === 'resolved' ? $actor->id : null, 'resolved_at' => $status === 'resolved' ? now() : null,
            ]);
        }
    }

    private function createVolumeProfile(Organization $organization, OrganizationMembership $membership, User $actor, array $context): void
    {
        $customers = collect($context['customers']);
        $locations = collect($context['locations']);
        for ($i = 1; $i <= 242; $i++) {
            $customer = Customer::query()->create([
                'organization_id' => $organization->id, 'type' => $i % 5 === 0 ? 'individual' : 'business',
                'display_name' => sprintf('EXAMPLE LOAD — Customer %03d', $i), 'phone' => sprintf('(940) 555-%04d', 3000 + $i),
                'phone_normalized' => sprintf('940555%04d', 3000 + $i), 'email' => sprintf('example.load%03d@newday.test', $i),
                'status' => 'active', 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            $customers->push($customer);
            $location = ServiceLocation::query()->create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id,
                'name' => sprintf('EXAMPLE LOAD — Site %03d', $i), 'address_line_1' => (1000 + $i).' Load Test Road',
                'city' => $i % 3 === 0 ? 'Graham' : 'Bryson', 'state' => 'TX', 'postal_code' => '76427',
                'timezone' => $organization->timezone, 'is_primary' => true, 'active' => true,
                'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            $locations->push($location);
            if ($i <= 148) {
                $locations->push(ServiceLocation::query()->create([
                    'organization_id' => $organization->id, 'customer_id' => $customer->id,
                    'name' => sprintf('EXAMPLE LOAD — Secondary %03d', $i), 'address_line_1' => (2000 + $i).' Load Test Road',
                    'city' => 'Jacksboro', 'state' => 'TX', 'postal_code' => '76458', 'timezone' => $organization->timezone,
                    'is_primary' => false, 'active' => true, 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
                ]));
            }
        }

        $today = CarbonImmutable::today($organization->timezone);
        $volumeVisits = collect();
        for ($i = 1; $i <= 488; $i++) {
            $customer = $customers[8 + (($i - 1) % 242)];
            $location = $locations->firstWhere('customer_id', $customer->id);
            $ticket = ServiceTicket::query()->create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id,
                'ticket_number' => $this->ticketNumbers->next($organization), 'title' => sprintf('EXAMPLE LOAD — Service request %03d', $i),
                'description' => 'Synthetic performance-volume request.', 'priority' => ['low', 'normal', 'normal', 'high'][$i % 4],
                'source' => 'internal', 'status' => 'open', 'purpose' => 'service_call', 'billing_disposition' => 'billable',
                'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            $visitCount = $i <= 11 ? 3 : 2;
            for ($j = 1; $j <= $visitCount; $j++) {
                $days = $i <= 10 ? (($i + $j) % 7) + 1 : 30 + (($i + $j) % 335);
                $visit = $this->visits->create($ticket, [
                    'service_location_id' => $location->id, 'status' => 'assigned', 'timezone' => $organization->timezone,
                    'scheduled_start_at' => $today->addDays($days)->setTime(8 + ($i % 7), 0)->utc(),
                    'scheduled_end_at' => $today->addDays($days)->setTime(9 + ($i % 7), 0)->utc(),
                    'scheduled_by_id' => $actor->id, 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
                ]);
                VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true, 'assigned_by_id' => $actor->id]);
                $volumeVisits->push($visit);
            }
        }

        $disk = (string) config('filesystems.default', 'local');
        foreach ($volumeVisits->take(192) as $index => $visit) {
            $closeout = $this->submittedCloseout($organization, $visit, $actor, 'resolved', 1);
            $visit->update(['status' => 'pending_closeout']);
            $mediaCount = $index < 111 ? 3 : 2;
            for ($media = 1; $media <= $mediaCount; $media++) {
                $key = "local-examples/volume/{$closeout->id}/{$media}.jpg";
                $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2Q==');
                Storage::disk($disk)->put($key, $bytes);
                VisitMedia::query()->create([
                    'organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id,
                    'uploader_id' => $actor->id, 'storage_disk' => $disk, 'storage_key' => $key,
                    'mime_type' => 'image/jpeg', 'byte_size' => strlen($bytes), 'category' => $media === 1 ? 'after' : 'other', 'state' => 'stored',
                ]);
            }
        }
    }
}
