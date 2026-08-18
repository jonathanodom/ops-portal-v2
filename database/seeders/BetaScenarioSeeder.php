<?php

namespace Database\Seeders;

use App\Domain\CatalogDefaults;
use App\Domain\InvoiceWorkflow;
use App\Domain\VisitCreator;
use App\Models\BillingHandoff;
use App\Models\BillingLaborRate;
use App\Models\Closeout;
use App\Models\CloseoutReview;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\PaymentProviderConfiguration;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\VisitAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BetaScenarioSeeder extends Seeder
{
    public function run(CatalogDefaults $catalogDefaults, VisitCreator $visitCreator): void
    {
        $this->call(AccessControlSeeder::class);
        $password = (string) env('BETA_DEMO_PASSWORD');
        if ($password === '' || str_contains($password, 'replace-with')) {
            throw new \RuntimeException('Set BETA_DEMO_PASSWORD in the untracked .env.beta before seeding.');
        }

        $organization = Organization::query()->create([
            'name' => 'NewDay Beta Validation', 'legal_name' => 'NewDay Beta Validation LLC',
            'email' => 'billing@newdaytech.test', 'phone' => '555-0100',
            'address_line_1' => '100 Beta Office Way', 'city' => 'Austin', 'state' => 'TX',
            'postal_code' => '78701', 'country_code' => 'US', 'slug' => 'beta-validation',
            'timezone' => 'America/Chicago', 'active' => true,
        ]);
        $catalogDefaults->ensureFor($organization);
        $memberships = [];
        foreach (['super_admin' => 'Super Admin', 'dispatcher' => 'Dispatcher', 'technician' => 'Technician', 'reviewer' => 'Reviewer', 'billing' => 'Billing'] as $roleKey => $name) {
            $user = User::query()->create([
                'name' => "Beta {$name}",
                'email' => "beta.{$roleKey}@newdaytech.test",
                'password' => Hash::make($password),
                'status' => 'active',
            ]);
            $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
            $membership->roles()->sync([Role::query()->where('key', $roleKey)->valueOrFail('id')]);
            $memberships[$roleKey] = $membership;
        }

        $scenarios = [
            ['code' => 'A', 'title' => 'Resolved with photo and acknowledgment', 'description' => 'Complete this visit as resolved, attach an after photo, capture customer acknowledgment, approve it, and acknowledge its billing handoff.'],
            ['code' => 'B', 'title' => 'Needs return trip and second visit', 'description' => 'Submit this visit as needs return trip, approve it, schedule and complete the linked return visit, then approve the resolved return.'],
            ['code' => 'C', 'title' => 'Return, correct, resubmit, and approve', 'description' => 'Submit this visit, return it from office with instructions, revise the new version, resubmit it, and approve it.'],
        ];

        foreach ($scenarios as $offset => $scenario) {
            $customer = Customer::query()->create([
                'organization_id' => $organization->id, 'type' => 'business',
                'display_name' => "BETA Scenario {$scenario['code']} Customer", 'status' => 'active',
                'created_by_id' => $memberships['super_admin']->user_id, 'updated_by_id' => $memberships['super_admin']->user_id,
            ]);
            $contact = Contact::query()->create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id,
                'name' => "Scenario {$scenario['code']} Representative", 'email' => "scenario{$scenario['code']}@example.test",
                'is_preferred' => true, 'active' => true,
            ]);
            $location = ServiceLocation::query()->create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id, 'primary_contact_id' => $contact->id,
                'name' => "BETA Scenario {$scenario['code']} Site", 'address_line_1' => (100 + $offset).' Beta Way',
                'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'timezone' => 'America/Chicago',
                'access_instructions' => 'Use the marked beta entrance.', 'is_primary' => true, 'active' => true,
            ]);
            $ticket = ServiceTicket::query()->create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id,
                'contact_id' => $contact->id, 'ticket_number' => 'NDT-ST-2026-'.(9001 + $offset), 'title' => "BETA {$scenario['code']}: {$scenario['title']}",
                'description' => $scenario['description'], 'customer_visible_summary' => 'Beta workflow validation only.',
                'priority' => $offset === 1 ? 'high' : 'normal', 'source' => 'internal', 'status' => 'open',
                'created_by_id' => $memberships['dispatcher']->user_id, 'updated_by_id' => $memberships['dispatcher']->user_id,
            ]);
            $visit = $visitCreator->create($ticket, [
                'service_location_id' => $location->id,
                'status' => 'assigned', 'timezone' => 'America/Chicago',
                'scheduled_start_at' => now()->startOfDay()->addHours(14 + $offset),
                'scheduled_end_at' => now()->startOfDay()->addHours(15 + $offset),
                'scheduled_by_id' => $memberships['dispatcher']->user_id,
                'created_by_id' => $memberships['dispatcher']->user_id, 'updated_by_id' => $memberships['dispatcher']->user_id,
            ]);
            VisitAssignment::query()->create([
                'organization_id' => $organization->id, 'visit_id' => $visit->id,
                'organization_membership_id' => $memberships['technician']->id, 'is_lead' => true,
                'assigned_by_id' => $memberships['dispatcher']->user_id,
            ]);
        }

        $this->call(BetaVolumeSeeder::class);
        $this->call(BetaProjectsSeeder::class);

        OrganizationBillingSetting::query()->create([
            'organization_id' => $organization->id, 'seller_name' => 'NewDay Tech', 'seller_legal_name' => 'NewDay Tech LLC',
            'seller_email' => 'billing@newdaytech.test', 'seller_phone' => '555-0100', 'seller_address_line_1' => '100 Beta Office Way',
            'seller_city' => 'Austin', 'seller_state' => 'TX', 'seller_postal_code' => '78701', 'default_currency' => 'USD',
            'default_payment_terms' => 'due_on_receipt', 'default_tax_rate_basis_points' => 825, 'updated_by_id' => $memberships['super_admin']->user_id,
        ]);
        BillingLaborRate::query()->create(['organization_id' => $organization->id, 'name' => 'Standard', 'hourly_rate_cents' => 12500, 'is_default' => true, 'active' => true, 'created_by_id' => $memberships['super_admin']->user_id]);
        $closeout = Closeout::query()->where('organization_id', $organization->id)->firstOrFail();
        $visit = $closeout->visit;
        $ticket = $visit->serviceTicket;
        $visit->update(['status' => 'approved']);
        $ticket->update(['status' => 'completed']);
        CloseoutReview::query()->create(['organization_id' => $organization->id, 'closeout_id' => $closeout->id, 'reviewer_id' => $memberships['super_admin']->user_id, 'decision' => 'approved', 'self_review_override' => true, 'decision_token' => (string) Str::uuid(), 'decided_at' => now()]);
        $handoff = BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'ready', 'created_by_id' => $memberships['super_admin']->user_id]);
        $workflow = app(InvoiceWorkflow::class);
        $invoice = $workflow->createFromHandoff($handoff, $memberships['super_admin']->user, (string) Str::uuid());
        PaymentProviderConfiguration::query()->create(['organization_id' => $organization->id, 'public_id' => (string) Str::uuid(), 'provider' => 'stripe', 'environment' => 'test', 'api_secret' => 'beta-fake-secret', 'webhook_secret' => 'beta-fake-webhook', 'credential_fingerprint' => 'BETA00000000', 'enabled' => true, 'connection_status' => 'connected', 'external_account_id' => 'beta-account', 'updated_by_id' => $memberships['super_admin']->user_id]);
        $invoice->forceFill(['preferred_payment_provider' => 'stripe'])->save();
        $workflow->addLine($invoice, $memberships['super_admin']->user, ['line_type' => 'service_charge', 'description' => 'Beta invoice presentation fixture', 'quantity_millis' => 1000, 'unit' => 'service', 'unit_price_cents' => 10000, 'included' => true, 'taxable' => true, 'override_reason' => 'Synthetic beta fixture']);
        $workflow->markReady($invoice->fresh(), $memberships['super_admin']->user);
        $workflow->issue($invoice->fresh(), $memberships['super_admin']->user, (string) Str::uuid());

        $draftCloseout = Closeout::query()->where('organization_id', $organization->id)->whereKeyNot($closeout->id)
            ->whereDoesntHave('visit.serviceTicket.billingHandoff')->firstOrFail();
        $draftVisit = $draftCloseout->visit;
        $draftTicket = $draftVisit->serviceTicket;
        $draftVisit->update(['status' => 'approved']);
        $draftTicket->update(['status' => 'completed']);
        CloseoutReview::query()->create(['organization_id' => $organization->id, 'closeout_id' => $draftCloseout->id, 'reviewer_id' => $memberships['super_admin']->user_id, 'decision' => 'approved', 'self_review_override' => true, 'decision_token' => (string) Str::uuid(), 'decided_at' => now()]);
        $draftHandoff = BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $draftTicket->id, 'visit_id' => $draftVisit->id, 'closeout_id' => $draftCloseout->id, 'status' => 'ready', 'created_by_id' => $memberships['super_admin']->user_id]);
        $draftInvoice = $workflow->createFromHandoff($draftHandoff, $memberships['super_admin']->user, (string) Str::uuid());
        $workflow->addLine($draftInvoice, $memberships['super_admin']->user, ['line_type' => 'service_charge', 'description' => 'Draft invoice workspace fixture', 'quantity_millis' => 1000, 'unit' => 'service', 'unit_price_cents' => 8500, 'included' => true, 'taxable' => true, 'override_reason' => 'Synthetic beta fixture']);
    }
}
