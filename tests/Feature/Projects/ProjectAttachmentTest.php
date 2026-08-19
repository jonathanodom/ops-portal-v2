<?php

namespace Tests\Feature\Projects;

use App\Jobs\DeleteRemovedProjectAttachment;
use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class ProjectAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        config(['project_attachments.disk' => 'project-files']);
        Storage::fake('project-files');
    }

    public function test_manager_uploads_private_images_and_documents_with_safe_metadata_and_audit(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);

        $uploads = [
            ['site_photo', UploadedFile::fake()->image('site photo.jpg'), 'image/jpeg', 'jpg'],
            ['design_document', UploadedFile::fake()->create('design.pdf', 10, 'application/pdf'), 'application/pdf', 'pdf'],
            ['design_document', $this->officeFile('scope.docx', 'docx'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'],
            ['equipment_list', $this->officeFile('equipment.xlsx', 'xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
            ['reference', UploadedFile::fake()->create('inventory.csv', 1, 'text/csv'), 'text/csv', 'csv'],
            ['reference', UploadedFile::fake()->create('readme.txt', 1, 'text/plain'), 'text/plain', 'txt'],
        ];

        foreach ($uploads as [$category, $file, $mime, $extension]) {
            $this->actingAs($admin)->post(route('office.projects.attachments.store', $project), [
                'category' => $category,
                'file' => $file,
                'caption' => 'Private context that must not enter audit metadata.',
            ])->assertRedirect();

            $attachment = ProjectAttachment::query()->latest('id')->firstOrFail();
            $this->assertSame($organization->id, $attachment->organization_id);
            $this->assertSame($project->id, $attachment->project_id);
            $this->assertSame($admin->id, $attachment->uploaded_by_id);
            $this->assertSame($mime, $attachment->mime_type);
            $this->assertMatchesRegularExpression('/project-attachments\/\d{4}\/\d{2}\/[0-9a-f-]{36}\.'.$extension.'/', $attachment->storage_key);
            $this->assertStringNotContainsString(pathinfo($attachment->original_name, PATHINFO_FILENAME), $attachment->storage_key);
            Storage::disk('project-files')->assertExists($attachment->storage_key);
        }

        $events = AuditEvent::query()->where('event_type', 'project_attachment.uploaded')->get();
        $this->assertCount(count($uploads), $events);
        $this->assertTrue($events->every(fn (AuditEvent $event) => $event->subject_type === $project->getMorphClass() && $event->subject_id === $project->id));
        $this->assertStringNotContainsString('Private context', $events->pluck('metadata')->toJson());
        $this->assertStringNotContainsString('project-attachments/', $events->pluck('metadata')->toJson());
    }

    public function test_validation_rejects_unknown_categories_unsafe_spoofed_macro_and_oversized_files(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);

        $this->actingAs($admin)->post(route('office.projects.attachments.store', $project), [
            'category' => 'unknown', 'file' => UploadedFile::fake()->create('safe.pdf', 1, 'application/pdf'),
        ])->assertSessionHasErrors('category');
        foreach ([
            UploadedFile::fake()->create('payload.exe', 1, 'application/x-msdownload'),
            UploadedFile::fake()->create('page.html', 1, 'text/html'),
            UploadedFile::fake()->create('vector.svg', 1, 'image/svg+xml'),
            UploadedFile::fake()->create('spoofed.pdf', 1, 'text/plain'),
            UploadedFile::fake()->create('archive.zip', 1, 'application/zip'),
        ] as $file) {
            $this->actingAs($admin)->post(route('office.projects.attachments.store', $project), [
                'category' => 'other', 'file' => $file,
            ])->assertSessionHasErrors('file');
        }
        $this->actingAs($admin)->post(route('office.projects.attachments.store', $project), [
            'category' => 'other', 'file' => UploadedFile::fake()->create('too-large.pdf', 20481, 'application/pdf'),
        ])->assertSessionHasErrors('file');
        $this->actingAs($admin)->post(route('office.projects.attachments.store', $project), [
            'category' => 'other', 'file' => $this->officeFile('macro.docx', 'docx', true),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('project_attachments', 0);
    }

    public function test_original_name_is_path_and_control_character_safe(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);

        $this->actingAs($admin)->post(route('office.projects.attachments.store', $project), [
            'category' => 'reference',
            'file' => UploadedFile::fake()->create("..\\private\\bad\x01name.txt", 1, 'text/plain'),
        ])->assertRedirect();

        $this->assertSame('badname.txt', ProjectAttachment::query()->sole()->original_name);
    }

    public function test_view_only_can_list_view_and_download_but_cannot_mutate(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        [, $reviewer] = $this->member('reviewer', $organization);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $attachment = $this->storedAttachment($project, $admin);

        $this->actingAs($reviewer)->get(route('office.projects.show', $project))->assertOk()->assertSee($attachment->original_name)->assertDontSee('Upload Project attachment');
        $this->actingAs($reviewer)->get(route('office.projects.attachments.show', [$project, $attachment]))
            ->assertOk()->assertHeader('cache-control', 'no-store, private')->assertHeader('x-content-type-options', 'nosniff')->assertHeader('content-type', 'application/pdf');
        $this->actingAs($reviewer)->get(route('office.projects.attachments.download', [$project, $attachment]))->assertOk();
        $this->actingAs($reviewer)->post(route('office.projects.attachments.store', $project), [
            'category' => 'reference', 'file' => UploadedFile::fake()->create('denied.pdf', 1, 'application/pdf'),
        ])->assertForbidden();
        $this->actingAs($reviewer)->delete(route('office.projects.attachments.destroy', [$project, $attachment]))->assertForbidden();
    }

    public function test_task_management_alone_does_not_grant_attachment_mutation_and_explicit_denial_wins(): void
    {
        [$organization, $admin, $adminMembership] = $this->member('super_admin');
        [, $reviewer, $reviewerMembership] = $this->member('reviewer', $organization);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $reviewerMembership->capabilityOverrides()->attach(Capability::query()->where('key', 'projects.tasks.manage')->firstOrFail(), ['effect' => 'grant']);

        $this->actingAs($reviewer)->post(route('office.projects.attachments.store', $project), [
            'category' => 'reference', 'file' => UploadedFile::fake()->create('denied.pdf', 1, 'application/pdf'),
        ])->assertForbidden();

        $adminMembership->capabilityOverrides()->attach(Capability::query()->where('key', 'projects.manage')->firstOrFail(), ['effect' => 'deny']);
        $this->actingAs($admin)->post(route('office.projects.attachments.store', $project), [
            'category' => 'reference', 'file' => UploadedFile::fake()->create('denied-too.pdf', 1, 'application/pdf'),
        ])->assertForbidden();
        $reviewerMembership->update(['status' => 'inactive']);
        $this->actingAs($reviewer)->get(route('office.projects.show', $project))->assertForbidden();
    }

    public function test_cross_organization_and_cross_project_attachment_probing_returns_not_found(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        [$otherOrganization, $otherAdmin] = $this->member('super_admin');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $otherProject = Project::factory()->create(['organization_id' => $otherOrganization->id, 'status' => 'active']);
        $attachment = $this->storedAttachment($project, $admin);

        $this->actingAs($otherAdmin)->get(route('office.projects.attachments.show', [$project, $attachment]))->assertNotFound();
        $this->actingAs($admin)->get(route('office.projects.attachments.show', [$otherProject, $attachment]))->assertNotFound();
        $this->actingAs($admin)->delete(route('office.projects.attachments.destroy', [$otherProject, $attachment]))->assertNotFound();
    }

    public function test_removal_retains_metadata_blocks_retrieval_and_cleans_object_after_commit(): void
    {
        Queue::fake();
        [$organization, $admin] = $this->member('super_admin');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $attachment = $this->storedAttachment($project, $admin);

        $this->actingAs($admin)->delete(route('office.projects.attachments.destroy', [$project, $attachment]))->assertRedirect();
        $attachment->refresh();
        $this->assertSame('removed', $attachment->state);
        $this->assertNotNull($attachment->removed_at);
        $this->assertSame($admin->id, $attachment->removed_by_id);
        $this->actingAs($admin)->get(route('office.projects.attachments.show', [$project, $attachment]))->assertNotFound();
        Queue::assertPushed(DeleteRemovedProjectAttachment::class, fn ($job) => $job->attachmentId === $attachment->id);
        $event = AuditEvent::query()->where('event_type', 'project_attachment.removed')->sole();
        $this->assertSame($project->id, $event->subject_id);

        Queue::fake()->except(DeleteRemovedProjectAttachment::class);
        (new DeleteRemovedProjectAttachment($attachment->id))->handle();
        Storage::disk('project-files')->assertMissing($attachment->storage_key);
    }

    public function test_completed_and_canceled_projects_keep_files_readable_but_reject_upload_and_removal(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        foreach (['completed', 'canceled'] as $status) {
            $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => $status]);
            $attachment = $this->storedAttachment($project, $admin, $status.'.pdf');
            $this->actingAs($admin)->get(route('office.projects.attachments.show', [$project, $attachment]))->assertOk();
            $this->actingAs($admin)->post(route('office.projects.attachments.store', $project), [
                'category' => 'reference', 'file' => UploadedFile::fake()->create('blocked.pdf', 1, 'application/pdf'),
            ])->assertSessionHasErrors('project');
            $this->actingAs($admin)->delete(route('office.projects.attachments.destroy', [$project, $attachment]))->assertSessionHasErrors('project');
        }
    }

    public function test_storage_failure_leaves_no_database_record(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('project-files')->andReturn($filesystem);

        $this->actingAs($admin)->post(route('office.projects.attachments.store', $project), [
            'category' => 'reference', 'file' => UploadedFile::fake()->create('fails.pdf', 1, 'application/pdf'),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('project_attachments', 0);
    }

    public function test_database_failure_after_storage_removes_the_new_object(): void
    {
        [$organization, $admin] = $this->member('super_admin');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        ProjectAttachment::creating(fn () => throw new \RuntimeException('Simulated database persistence failure.'));

        try {
            $this->withoutExceptionHandling()->actingAs($admin)->post(route('office.projects.attachments.store', $project), [
                'category' => 'reference', 'file' => UploadedFile::fake()->create('new.pdf', 1, 'application/pdf'),
            ]);
            $this->fail('The simulated database persistence failure should be thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated database persistence failure.', $exception->getMessage());
            $this->assertSame([], Storage::disk('project-files')->allFiles());
            $this->assertDatabaseCount('project_attachments', 0);
        } finally {
            ProjectAttachment::flushEventListeners();
        }
    }

    /** @return array{Organization, User, OrganizationMembership} */
    private function member(string $role, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

        return [$organization, $user, $membership];
    }

    private function storedAttachment(Project $project, User $uploader, string $name = 'reference.pdf'): ProjectAttachment
    {
        $key = 'project-attachments/2026/08/'.fake()->uuid().'.pdf';
        Storage::disk('project-files')->put($key, 'private project attachment');

        return ProjectAttachment::query()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'uploaded_by_id' => $uploader->id,
            'category' => 'reference', 'storage_disk' => 'project-files', 'storage_key' => $key,
            'original_name' => $name, 'mime_type' => 'application/pdf', 'byte_size' => 26,
        ]);
    }

    private function officeFile(string $name, string $extension, bool $macro = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'project-attachment-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString($extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml', '<document/>');
        if ($macro) {
            $zip->addFromString($extension === 'docx' ? 'word/vbaProject.bin' : 'xl/vbaProject.bin', 'macro');
        }
        $zip->close();

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }
}
