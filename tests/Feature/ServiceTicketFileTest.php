<?php

namespace Tests\Feature;

use App\Jobs\DeleteRemovedServiceTicketFile;
use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketFile;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceTicketFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        config(['service_ticket_files.disk' => 'ticket-files']);
        Storage::fake('ticket-files');
    }

    public function test_authorized_user_uploads_and_downloads_private_ticket_file_with_safe_audit(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        $ticket = $this->ticket($organization);

        $response = $this->actingAs($admin)->post(route('office.service-tickets.files.store', $ticket), [
            'file' => UploadedFile::fake()->create('network diagram.pdf', 100, 'application/pdf'),
            'caption' => 'Reference drawing for the office rack.',
        ]);

        $response->assertRedirect();
        $file = ServiceTicketFile::query()->sole();
        $this->assertSame($organization->id, $file->organization_id);
        $this->assertSame($ticket->id, $file->service_ticket_id);
        $this->assertSame($admin->id, $file->uploaded_by_id);
        $this->assertSame('network diagram.pdf', $file->original_name);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertSame('Reference drawing for the office rack.', $file->caption);
        $this->assertStringNotContainsString('network', $file->storage_key);
        $this->assertMatchesRegularExpression('/service-ticket-files\/\d{4}\/\d{2}\/[0-9a-f-]{36}\.pdf/', $file->storage_key);
        Storage::disk('ticket-files')->assertExists($file->storage_key);

        $event = AuditEvent::query()->where('event_type', 'service_ticket_file.uploaded')->sole();
        $this->assertSame($file->id, $event->subject_id);
        $this->assertEquals([
            'ticket_id' => $ticket->id,
            'file_id' => $file->id,
            'mime_type' => 'application/pdf',
            'byte_size' => $file->byte_size,
        ], $event->metadata);
        $this->assertStringNotContainsString('Reference drawing', json_encode($event->metadata, JSON_THROW_ON_ERROR));

        $this->actingAs($admin)->get(route('office.service-ticket-files.show', $file))
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_write_authority_and_organization_scope_are_enforced(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        [, $reviewer] = $this->member('reviewer', $organization);
        [$otherOrganization, $otherAdmin] = $this->member('super_admin');
        $ticket = $this->ticket($organization);
        $file = $this->storedFile($ticket, $admin);

        $this->actingAs($reviewer)->post(route('office.service-tickets.files.store', $ticket), [
            'file' => UploadedFile::fake()->create('read-only.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
        $this->actingAs($reviewer)->delete(route('office.service-tickets.files.destroy', [$ticket, $file]))->assertForbidden();
        $this->actingAs($reviewer)->get(route('office.service-ticket-files.show', $file))->assertOk();

        $this->actingAs($otherAdmin)->get(route('office.service-ticket-files.show', $file))->assertNotFound();
        $this->actingAs($otherAdmin)->delete(route('office.service-tickets.files.destroy', [$ticket, $file]))->assertNotFound();
        $this->assertSame($otherOrganization->id, $otherAdmin->memberships()->sole()->organization_id);
    }

    public function test_explicit_capability_overrides_and_inactive_memberships_remain_authoritative(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        [, $reviewer] = $this->member('reviewer', $organization);
        $ticket = $this->ticket($organization);
        $dispatch = Capability::query()->where('key', 'dispatch.manage')->firstOrFail();
        $adminMembership = OrganizationMembership::query()->whereBelongsTo($admin)->sole();
        $reviewerMembership = OrganizationMembership::query()->whereBelongsTo($reviewer)->sole();

        $adminMembership->capabilityOverrides()->attach($dispatch, ['effect' => 'deny']);
        $this->actingAs($admin)->post(route('office.service-tickets.files.store', $ticket), [
            'file' => UploadedFile::fake()->create('denied.pdf', 10, 'application/pdf'),
        ])->assertForbidden();

        $reviewerMembership->capabilityOverrides()->attach($dispatch, ['effect' => 'grant']);
        $this->actingAs($reviewer)->post(route('office.service-tickets.files.store', $ticket), [
            'file' => UploadedFile::fake()->create('granted.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $reviewerMembership->update(['status' => 'inactive']);
        $this->actingAs($reviewer)->get(route('office.service-tickets.show', $ticket))->assertForbidden();
    }

    public function test_unsafe_spoofed_and_oversized_files_are_rejected(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        $ticket = $this->ticket($organization);

        $this->actingAs($admin)->from(route('office.service-tickets.show', $ticket))->post(route('office.service-tickets.files.store', $ticket), [
            'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors('file');
        $this->actingAs($admin)->from(route('office.service-tickets.show', $ticket))->post(route('office.service-tickets.files.store', $ticket), [
            'file' => UploadedFile::fake()->create('spoofed.pdf', 10, 'text/plain'),
        ])->assertSessionHasErrors('file');
        $this->actingAs($admin)->from(route('office.service-tickets.show', $ticket))->post(route('office.service-tickets.files.store', $ticket), [
            'file' => UploadedFile::fake()->create('oversized.pdf', 20481, 'application/pdf'),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('service_ticket_files', 0);
    }

    public function test_removal_preserves_metadata_blocks_download_and_queues_object_cleanup(): void
    {
        Queue::fake();
        [$organization, $admin] = $this->member('super_admin');
        $ticket = $this->ticket($organization);
        $file = $this->storedFile($ticket, $admin);

        $this->actingAs($admin)->delete(route('office.service-tickets.files.destroy', [$ticket, $file]))
            ->assertRedirect();

        $file->refresh();
        $this->assertSame('removed', $file->state);
        $this->assertNotNull($file->removed_at);
        $this->assertSame($admin->id, $file->removed_by_id);
        $this->actingAs($admin)->get(route('office.service-ticket-files.show', $file))->assertNotFound();
        Queue::assertPushed(DeleteRemovedServiceTicketFile::class, fn (DeleteRemovedServiceTicketFile $job) => $job->fileId === $file->id);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'service_ticket_file.removed',
            'subject_id' => $file->id,
        ]);
    }

    public function test_cleanup_job_deletes_only_an_object_marked_removed(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        $ticket = $this->ticket($organization);
        $active = $this->storedFile($ticket, $admin, 'active.pdf');
        $removed = $this->storedFile($ticket, $admin, 'removed.pdf');
        $removed->update(['state' => 'removed', 'removed_at' => now(), 'removed_by_id' => $admin->id]);

        (new DeleteRemovedServiceTicketFile($active->id))->handle();
        (new DeleteRemovedServiceTicketFile($removed->id))->handle();

        Storage::disk('ticket-files')->assertExists($active->storage_key);
        Storage::disk('ticket-files')->assertMissing($removed->storage_key);
        $this->assertDatabaseHas('service_ticket_files', ['id' => $removed->id, 'state' => 'removed']);
    }

    public function test_ticket_detail_lists_only_active_files_and_hides_write_controls_from_viewer(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        [, $reviewer] = $this->member('reviewer', $organization);
        $ticket = $this->ticket($organization);
        $active = $this->storedFile($ticket, $admin, 'active-reference.pdf');
        $removed = $this->storedFile($ticket, $admin, 'removed-reference.pdf');
        $removed->update(['state' => 'removed', 'removed_at' => now(), 'removed_by_id' => $admin->id]);

        $this->actingAs($reviewer)->get(route('office.service-tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Ticket files')
            ->assertSee($active->original_name)
            ->assertDontSee($removed->original_name)
            ->assertDontSee('Upload Ticket file')
            ->assertDontSee(route('office.service-tickets.files.destroy', [$ticket, $active]), false);
    }

    /** @return array{Organization, User} */
    private function member(string $role, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

        return [$organization, $user];
    }

    private function ticket(Organization $organization): ServiceTicket
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
        ]);

        return ServiceTicket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => 'NDT-ST-2026-'.str_pad((string) $customer->id, 4, '0', STR_PAD_LEFT),
            'title' => 'Ticket files fixture',
            'priority' => 'normal',
            'source' => 'internal',
            'purpose' => 'service_call',
            'billing_disposition' => 'billable',
            'status' => 'open',
        ]);
    }

    private function storedFile(ServiceTicket $ticket, User $uploader, string $name = 'reference.pdf'): ServiceTicketFile
    {
        $key = 'service-ticket-files/2026/08/'.fake()->uuid().'.pdf';
        Storage::disk('ticket-files')->put($key, 'private ticket file');

        return ServiceTicketFile::query()->create([
            'organization_id' => $ticket->organization_id,
            'service_ticket_id' => $ticket->id,
            'uploaded_by_id' => $uploader->id,
            'storage_disk' => 'ticket-files',
            'storage_key' => $key,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'byte_size' => 19,
            'caption' => 'Ticket reference',
        ]);
    }
}
