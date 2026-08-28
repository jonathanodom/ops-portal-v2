<?php

namespace App\Domain\Commercial;

use App\Domain\Projects\Attachments\ProjectAttachmentType;
use App\Jobs\DeleteRemovedCommercialRevisionMedia;
use App\Models\CommercialRevision;
use App\Models\CommercialRevisionMedia;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CommercialRevisionMediaWorkflow
{
    public function __construct(private readonly ProjectAttachmentType $types, private readonly QuoteWorkflow $quotes, private readonly AuditRecorder $audit) {}

    public function upload(CommercialRevision $revision, User $actor, UploadedFile $file, ?string $caption): CommercialRevisionMedia
    {
        $type = $this->types->inspect($file);
        $disk = (string) config('commercial.proposal_disk', 'local');
        $key = 'commercial/proposals/'.now()->format('Y/m').'/'.Str::uuid().'.'.$type['extension'];
        $stored = false;
        try {
            return DB::transaction(function () use ($revision, $actor, $file, $caption, $type, $disk, $key, &$stored): CommercialRevisionMedia {
                $revision = $this->lockDraft($revision);
                $stored = (bool) Storage::disk($disk)->putFileAs(dirname($key), $file, basename($key));
                if (! $stored) {
                    throw ValidationException::withMessages(['file' => 'The Proposal media could not be stored. Please retry.']);
                }
                $media = $revision->media()->create(['organization_id' => $revision->organization_id, 'media_type' => str_starts_with($type['mime_type'], 'image/') ? 'image' : 'document', 'storage_disk' => $disk, 'storage_key' => $key, 'original_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 255, ''), 'mime_type' => $type['mime_type'], 'byte_size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'caption' => $caption, 'uploaded_by_id' => $actor->id]);
                $this->quotes->refreshContent($revision, $actor);
                $this->audit->record($revision->document->opportunity->organization, $actor, 'quote.media_uploaded', $revision->document->opportunity, ['quote_id' => $revision->commercial_document_id, 'revision_id' => $revision->id, 'media_id' => $media->id, 'media_type' => $media->media_type, 'mime_type' => $media->mime_type, 'byte_size' => $media->byte_size]);

                return $media;
            });
        } catch (\Throwable $exception) {
            if ($stored) {
                Storage::disk($disk)->delete($key);
            }
            throw $exception;
        }
    }

    public function embed(CommercialRevision $revision, User $actor, string $url, ?string $caption): CommercialRevisionMedia
    {
        return DB::transaction(function () use ($revision, $actor, $url, $caption): CommercialRevisionMedia {
            $revision = $this->lockDraft($revision);
            $media = $revision->media()->create(['organization_id' => $revision->organization_id, 'media_type' => 'video', 'embed_url' => $url, 'caption' => $caption, 'uploaded_by_id' => $actor->id]);
            $this->quotes->refreshContent($revision, $actor);
            $this->audit->record($revision->document->opportunity->organization, $actor, 'quote.video_embedded', $revision->document->opportunity, ['quote_id' => $revision->commercial_document_id, 'revision_id' => $revision->id, 'media_id' => $media->id, 'media_type' => 'video']);

            return $media;
        });
    }

    public function remove(CommercialRevision $revision, CommercialRevisionMedia $media, User $actor): void
    {
        DB::transaction(function () use ($revision, $media, $actor): void {
            $revision = $this->lockDraft($revision);
            $media = $revision->media()->where('state', 'stored')->lockForUpdate()->findOrFail($media->id);
            $media->update(['state' => 'removed', 'removed_at' => now(), 'removed_by_id' => $actor->id]);
            $this->quotes->refreshContent($revision, $actor);
            $this->audit->record($revision->document->opportunity->organization, $actor, 'quote.media_removed', $revision->document->opportunity, ['quote_id' => $revision->commercial_document_id, 'revision_id' => $revision->id, 'media_id' => $media->id, 'media_type' => $media->media_type]);
            if ($media->storage_key) {
                DeleteRemovedCommercialRevisionMedia::dispatch($media->id)->afterCommit();
            }
        });
    }

    private function lockDraft(CommercialRevision $revision): CommercialRevision
    {
        $revision = CommercialRevision::query()->with('document.opportunity.organization')->whereKey($revision->id)->lockForUpdate()->firstOrFail();
        if (! $revision->isEditable()) {
            throw ValidationException::withMessages(['revision' => 'Proposal media may be changed only on a Draft revision.']);
        }

        return $revision;
    }
}
