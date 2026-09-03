<?php

namespace Tests\Feature;

use App\Domain\Commercial\LeadIntakeCreator;
use App\Domain\Notifications\NewLeadSubmittedNotifier;
use App\Jobs\DeliverPortalNotificationEmail;
use App\Mail\StaffPortalNotificationMail;
use App\Models\CommercialLeadIntake;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PortalNotificationEvent;
use App\Models\PortalNotificationPreference;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class NewLeadNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        config([
            'cache.default' => 'array',
            'lead-intake.organization_slug' => null,
            'lead-intake.turnstile_secret' => null,
        ]);
    }

    public function test_valid_website_lead_persists_one_normalized_notification_for_each_sales_recipient(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        [$admin] = $this->member($organization, 'super_admin');
        [$dispatcher] = $this->member($organization, 'dispatcher');
        [$reviewer] = $this->member($organization, 'reviewer');

        $this->postJson(route('api.public.v1.leads.store'), $this->validPayload())->assertCreated();

        $lead = CommercialLeadIntake::query()->sole();
        $event = PortalNotificationEvent::query()->with('recipients')->sole();
        $this->assertSame('lead.submitted', $event->event_key);
        $this->assertSame('New Lead — Jane Smith', $event->title);
        $this->assertSame('/office/leads/'.$lead->id, $event->action_url);
        $this->assertSame($lead->id, $event->related_id);
        $this->assertSame(['type' => 'capability', 'value' => 'opportunities.manage'], $event->audience);
        $this->assertEqualsCanonicalizing([$admin->id, $dispatcher->id], $event->recipients->pluck('user_id')->all());
        $this->assertNotContains($reviewer->id, $event->recipients->pluck('user_id')->all());
        $event->recipients->each(fn ($recipient) => $this->assertSame(['in_app', 'email'], $recipient->channels));
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 2);

        $this->actingAs($dispatcher)->getJson(route('notifications.recent'))
            ->assertOk()
            ->assertJsonPath('notifications.0.title', 'New Lead — Jane Smith');
    }

    public function test_channel_preferences_and_missing_email_do_not_remove_in_app_delivery(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        [$emailDisabled] = $this->member($organization, 'dispatcher');
        [$missingEmail] = $this->member($organization, 'dispatcher', '');
        PortalNotificationPreference::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $emailDisabled->id,
            'event_key' => 'lead.submitted',
            'email_enabled' => false,
        ]);

        $this->postJson(route('api.public.v1.leads.store'), $this->validPayload())->assertCreated();

        $event = PortalNotificationEvent::query()->with('recipients')->sole();
        $disabledRecipient = $event->recipients->firstWhere('user_id', $emailDisabled->id);
        $missingRecipient = $event->recipients->firstWhere('user_id', $missingEmail->id);
        $this->assertSame(['in_app'], $disabledRecipient->channels);
        $this->assertSame(['in_app', 'email'], $missingRecipient->channels);
        $this->assertNull($disabledRecipient->email_queued_at);
        $this->assertNull($missingRecipient->email_queued_at);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_event_execution_does_not_duplicate_notifications_or_email_jobs(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $this->member($organization, 'dispatcher');
        $lead = app(LeadIntakeCreator::class)->create($organization, [
            ...$this->normalizedLead(),
            'source' => 'website',
            'status' => 'received',
        ]);
        $notifier = app(NewLeadSubmittedNotifier::class);

        $first = $notifier->notify($lead);
        $replay = $notifier->notify($lead);

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('portal_notification_events', 1);
        $this->assertDatabaseCount('portal_notification_recipients', 1);
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 1);
    }

    public function test_manual_leads_do_not_emit_the_website_submission_event(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $this->member($organization, 'dispatcher');
        $lead = app(LeadIntakeCreator::class)->create($organization, [
            ...$this->normalizedLead(),
            'source' => 'manual',
            'status' => 'received',
        ]);

        $this->assertNull(app(NewLeadSubmittedNotifier::class)->notify($lead));
        $this->assertDatabaseCount('portal_notification_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_invalid_lead_and_notification_publication_failure_do_not_corrupt_lead_intake(): void
    {
        Queue::fake();
        Organization::factory()->create();
        $this->postJson(route('api.public.v1.leads.store'), [
            ...$this->validPayload(),
            'email' => 'invalid',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('commercial_lead_intakes', 0);
        $this->assertDatabaseCount('portal_notification_events', 0);
        Queue::assertNothingPushed();

        Schema::drop('portal_notification_events');
        Log::spy();

        $this->postJson(route('api.public.v1.leads.store'), $this->validPayload())->assertCreated();
        $this->assertDatabaseCount('commercial_lead_intakes', 1);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'New lead notification publication failed.'
            && $context['failure_type'] === 'QueryException');
    }

    public function test_email_job_sends_branded_normalized_content_once_and_records_failure_safely(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        [$dispatcher] = $this->member($organization, 'dispatcher');
        $lead = app(LeadIntakeCreator::class)->create($organization, [
            ...$this->normalizedLead(),
            'source' => 'website',
            'status' => 'received',
        ]);
        $event = app(NewLeadSubmittedNotifier::class)->notify($lead);
        $recipient = $event->recipients->sole();
        $job = new DeliverPortalNotificationEmail($recipient->id);

        Mail::fake();
        $job->handle();
        $job->handle();
        Mail::assertSent(StaffPortalNotificationMail::class, 1);
        Mail::assertSent(StaffPortalNotificationMail::class, fn (StaffPortalNotificationMail $mail): bool => $mail->hasTo($dispatcher->email)
            && $mail->event->is($event)
            && str_contains($mail->render(), 'NewDay Tech')
            && str_contains($mail->render(), 'View Lead')
            && str_contains($mail->render(), '/office/leads/'.$lead->id));
        $this->assertNotNull($recipient->fresh()->email_sent_at);

        $recipient->refresh()->update(['email_sent_at' => null]);
        Log::shouldReceive('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Portal notification email delivery failed.'
            && $context['recipient_id'] === $recipient->id
            && $context['failure_type'] === 'RuntimeException');
        $job->failed(new RuntimeException('secret provider response'));
        $this->assertNotNull($recipient->fresh()->email_failed_at);
        $this->assertTrue($lead->fresh()->exists);
        $this->assertTrue($event->fresh()->exists);
    }

    private function validPayload(): array
    {
        return [
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'phone' => '940-555-1212',
            'email' => 'jane@example.test',
            'customerType' => 'Residential',
            'zip' => '76450',
            'company' => null,
            'serviceInterest' => 'Wi-Fi & Networking',
            'selectedPlan' => null,
            'preferredContact' => 'Phone',
            'timeline' => 'Within 30 days',
            'details' => 'Need coverage across the property.',
            'originatingPage' => '/about-contact/',
            'utmSource' => 'google',
            'utmMedium' => 'cpc',
            'utmCampaign' => 'wifi',
            'utmTerm' => null,
            'utmContent' => null,
            'referrer' => 'https://www.google.com/',
            'website' => '',
            'consent' => true,
            'smsConsent' => false,
        ];
    }

    private function normalizedLead(): array
    {
        return [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'phone' => '940-555-1212',
            'email' => 'jane@example.test',
            'customer_type' => 'Residential',
            'zip' => '76450',
            'service_interest' => 'Wi-Fi & Networking',
            'preferred_contact' => 'Phone',
            'timeline' => 'Within 30 days',
            'details' => 'Need coverage across the property.',
        ];
    }

    /** @return array{User, OrganizationMembership} */
    private function member(Organization $organization, string $role, ?string $email = null): array
    {
        $user = User::factory()->create([
            'status' => 'active',
            ...($email !== null ? ['email' => $email] : []),
        ]);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $role)->sole());

        return [$user, $membership];
    }
}
