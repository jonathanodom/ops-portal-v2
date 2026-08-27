<?php

namespace App\Domain\Commercial;

use App\Domain\Projects\Attachments\ProjectAttachmentType;
use App\Jobs\DeleteRemovedOpportunityAttachment;
use App\Models\Opportunity;
use App\Models\OpportunityAttachment;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OpportunityAttachmentWorkflow
{
    public function __construct(private readonly ProjectAttachmentType $types, private readonly AuditRecorder $audit) {}

    public function store(Opportunity $opportunity, User $actor, UploadedFile $file, ?string $caption): OpportunityAttachment
    {
        $type = $this->types->inspect($file);
        $disk = (string) config('commercial.attachment_disk', 'local');
        $key = 'commercial/opportunities/'.now()->format('Y/m').'/'.Str::uuid().'.'.$type['extension'];
        $stored = false;
        try {
            return DB::transaction(function () use ($opportunity, $actor, $file, $caption, $type, $disk, $key, &$stored): OpportunityAttachment {
                $opportunity = $this->lockMutable($opportunity);
                $stored = (bool) Storage::disk($disk)->putFileAs(dirname($key), $file, basename($key));
                if (! $stored) {
                    throw ValidationException::withMessages(['file' => 'The Opportunity file could not be stored. Please retry.']);
                }
                $attachment = OpportunityAttachment::query()->create([
                    'organization_id' => $opportunity->organization_id, 'opportunity_id' => $opportunity->id,
                    'uploaded_by_id' => $actor->id, 'storage_disk' => $disk, 'storage_key' => $key,
                    'original_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 255, ''),
                    'mime_type' => $type['mime_type'], 'byte_size' => $file->getSize(), 'caption' => $caption,
                ]);
                $this->audit->record($opportunity->organization, $actor, 'opportunity_attachment.uploaded', $opportunity, ['attachment_id' => $attachment->id, 'mime_type' => $attachment->mime_type, 'byte_size' => $attachment->byte_size]);

                return $attachment;
            });
        } catch (\Throwable $exception) {
            if ($stored) {
                Storage::disk($disk)->delete($key);
            }
            throw $exception;
        }
    }

    public function remove(Opportunity $opportunity, OpportunityAttachment $attachment, User $actor): void
    {
        DB::transaction(function () use ($opportunity, $attachment, $actor): void {
            $opportunity = $this->lockMutable($opportunity);
            $attachment = OpportunityAttachment::query()->where('organization_id', $opportunity->organization_id)->where('opportunity_id', $opportunity->id)->where('state', 'stored')->lockForUpdate()->findOrFail($attachment->id);
            $attachment->update(['state' => 'removed', 'removed_at' => now(), 'removed_by_id' => $actor->id]);
            $this->audit->record($opportunity->organization, $actor, 'opportunity_attachment.removed', $opportunity, ['attachment_id' => $attachment->id, 'mime_type' => $attachment->mime_type, 'byte_size' => $attachment->byte_size]);
            DeleteRemovedOpportunityAttachment::dispatch($attachment->id)->afterCommit();
        });
    }

    private function lockMutable(Opportunity $opportunity): Opportunity
    {
        $opportunity = Opportunity::query()->with('stage')->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
        if ($opportunity->stage->semantic_kind === 'won') {
            throw ValidationException::withMessages(['opportunity' => 'Won Opportunities are final.']);
        }

        return $opportunity;
    }
}
