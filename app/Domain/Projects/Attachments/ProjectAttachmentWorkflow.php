<?php

namespace App\Domain\Projects\Attachments;

use App\Jobs\DeleteRemovedProjectAttachment;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProjectAttachmentWorkflow
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array{mime_type: string, extension: string} $type */
    public function store(Project $project, User $actor, UploadedFile $file, string $category, ?string $caption, array $type): ProjectAttachment
    {
        $disk = (string) config('project_attachments.disk');
        $key = 'project-attachments/'.now()->format('Y/m').'/'.Str::uuid().'.'.$type['extension'];
        $objectStored = false;

        try {
            return DB::transaction(function () use ($project, $actor, $file, $category, $caption, $type, $disk, $key, &$objectStored): ProjectAttachment {
                $project = $this->lockOpen($project);

                try {
                    $objectStored = (bool) Storage::disk($disk)->putFileAs(dirname($key), $file, basename($key));
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['file' => 'The Project attachment could not be stored. Please retry.']);
                }
                if (! $objectStored) {
                    throw ValidationException::withMessages(['file' => 'The Project attachment could not be stored. Please retry.']);
                }

                $attachment = ProjectAttachment::query()->create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'uploaded_by_id' => $actor->id,
                    'category' => $category,
                    'storage_disk' => $disk,
                    'storage_key' => $key,
                    'original_name' => $this->safeOriginalName($file->getClientOriginalName()),
                    'mime_type' => $type['mime_type'],
                    'byte_size' => $file->getSize(),
                    'caption' => filled($caption) ? $caption : null,
                ]);
                $this->audit->record($project->organization, $actor, 'project_attachment.uploaded', $project, [
                    'attachment_id' => $attachment->id,
                    'category' => $attachment->category,
                    'mime_type' => $attachment->mime_type,
                    'byte_size' => $attachment->byte_size,
                ]);

                return $attachment;
            });
        } catch (\Throwable $exception) {
            if ($objectStored) {
                Storage::disk($disk)->delete($key);
            }

            throw $exception;
        }
    }

    public function remove(Project $project, ProjectAttachment $attachment, User $actor): void
    {
        DB::transaction(function () use ($project, $attachment, $actor): void {
            $project = $this->lockOpen($project);
            $attachment = ProjectAttachment::query()
                ->where('organization_id', $project->organization_id)
                ->where('project_id', $project->id)
                ->where('state', 'stored')
                ->lockForUpdate()
                ->findOrFail($attachment->id);
            $attachment->update([
                'state' => 'removed',
                'removed_at' => now(),
                'removed_by_id' => $actor->id,
            ]);
            $this->audit->record($project->organization, $actor, 'project_attachment.removed', $project, [
                'attachment_id' => $attachment->id,
                'category' => $attachment->category,
                'mime_type' => $attachment->mime_type,
                'byte_size' => $attachment->byte_size,
            ]);
            DeleteRemovedProjectAttachment::dispatch($attachment->id)->afterCommit();
        });
    }

    private function lockOpen(Project $project): Project
    {
        $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        if (in_array($project->status, ['completed', 'canceled'], true)) {
            throw ValidationException::withMessages(['project' => 'Completed or canceled Projects cannot receive operational changes.']);
        }

        return $project;
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'file';

        return Str::limit($name, 255, '');
    }
}
