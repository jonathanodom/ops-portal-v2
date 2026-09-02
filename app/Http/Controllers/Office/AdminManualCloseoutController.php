<?php

namespace App\Http\Controllers\Office;

use App\Domain\AdminManualCloseoutWorkflow;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteRemovedVisitMedia;
use App\Models\Visit;
use App\Models\VisitMedia;
use App\Models\VisitPartProposal;
use App\Support\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminManualCloseoutController extends Controller
{
    public function start(Request $request, string $visit, AdminManualCloseoutWorkflow $workflow): RedirectResponse
    {
        $visit = $this->visit($request, $visit);
        $workflow->start($visit, $request->user());

        return $this->backToModal($visit, 'Administrative closeout draft ready.');
    }

    public function save(Request $request, string $visit, AdminManualCloseoutWorkflow $workflow): RedirectResponse|JsonResponse
    {
        $visit = $this->visit($request, $visit);
        $data = $this->closeoutData($request);
        $contentVersion = (int) $data['content_version'];
        unset($data['content_version']);
        if (! $workflow->save($visit, $request->user(), $data, $contentVersion)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'This closeout changed in another session. Your entries remain on screen; review the warning before explicitly retrying.',
                    'content_version' => $visit->fresh()->currentCloseout?->content_version,
                ], 409);
            }

            return $this->backToModal($visit)->withInput()->withErrors([
                'content_version' => 'This closeout changed in another session. Review the latest draft before retrying.',
            ]);
        }
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Administrative closeout draft saved.',
                'content_version' => $visit->fresh()->currentCloseout?->content_version,
            ]);
        }

        return $this->backToModal($visit, 'Administrative closeout draft saved.');
    }

    public function complete(Request $request, string $visit, AdminManualCloseoutWorkflow $workflow, AuditRecorder $audit): RedirectResponse
    {
        $visit = $this->visit($request, $visit);
        $data = $this->closeoutData($request) + $request->validate([
            'administrative_completion_reason' => ['required', 'string', 'max:5000'],
            'completion_token' => ['required', 'uuid'],
            'confirm_administrative_completion' => ['required', 'accepted'],
            'acknowledgment_confirmed' => ['sometimes', 'accepted'],
        ]);
        if (filled($data['representative_name'] ?? null) && ! $request->boolean('acknowledgment_confirmed')) {
            return $this->backToModal($visit)->withInput()->withErrors([
                'acknowledgment_confirmed' => 'Confirm the customer acknowledgment before completing the ticket.',
            ]);
        }
        $contentVersion = (int) $data['content_version'];
        $reason = $data['administrative_completion_reason'];
        $token = $data['completion_token'];
        unset($data['content_version'], $data['administrative_completion_reason'], $data['completion_token'], $data['confirm_administrative_completion'], $data['acknowledgment_confirmed']);
        try {
            $workflow->complete($visit, $request->user(), $data, $contentVersion, $reason, $token);
        } catch (ValidationException $exception) {
            $audit->record($request->attributes->get('organization'), $request->user(), 'closeout.administrative_completion_rejected', $visit, [
                'ticket_id' => $visit->service_ticket_id,
                'visit_id' => $visit->id,
                'invalid_fields' => array_keys($exception->errors()),
            ]);
            throw $exception;
        }

        return redirect()->route('office.service-tickets.show', $visit->service_ticket_id)
            ->with('status', 'Administrative closeout approved. The Service Ticket is complete and its billing handoff is ready.');
    }

    public function addPart(Request $request, string $visit, AdminManualCloseoutWorkflow $workflow, AuditRecorder $audit): RedirectResponse
    {
        $visit = $this->visit($request, $visit);
        $closeout = $workflow->start($visit, $request->user());
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'unit' => ['nullable', 'string', 'max:40'],
            'serial_mac' => ['nullable', 'string', 'max:255'],
            'billing_treatment' => ['required', Rule::in(array_keys(config('field_execution.billing_treatments')))],
            'technician_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $part = VisitPartProposal::query()->create($data + [
            'organization_id' => $visit->organization_id,
            'visit_id' => $visit->id,
            'closeout_id' => $closeout->id,
            'proposed_by_id' => $request->user()->id,
        ]);
        $audit->record($request->attributes->get('organization'), $request->user(), 'visit_part_proposal.created', $part, ['visit_id' => $visit->id]);

        return $this->backToModal($visit, 'Part or equipment proposal added.');
    }

    public function removePart(Request $request, string $visit, string $part, AdminManualCloseoutWorkflow $workflow, AuditRecorder $audit): RedirectResponse
    {
        $visit = $this->visit($request, $visit);
        $workflow->start($visit, $request->user());
        $part = VisitPartProposal::query()->where('visit_id', $visit->id)->where('closeout_id', $visit->current_closeout_id)->whereNull('removed_at')->findOrFail($part);
        $part->update(['removed_at' => now()]);
        $audit->record($request->attributes->get('organization'), $request->user(), 'visit_part_proposal.removed', $part, ['visit_id' => $visit->id]);

        return $this->backToModal($visit, 'Part or equipment proposal removed.');
    }

    public function upload(Request $request, string $visit, AdminManualCloseoutWorkflow $workflow, AuditRecorder $audit): JsonResponse
    {
        $visit = $this->visit($request, $visit);
        $closeout = $workflow->start($visit, $request->user());
        if ($closeout->media()->where('state', 'stored')->count() >= config('field_execution.max_photos')) {
            return response()->json(['message' => 'Photo limit reached.'], 422);
        }
        $data = $request->validate([
            'photo' => ['required', 'file', 'max:'.config('field_execution.max_photo_kb'), 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif'],
            'category' => ['required', Rule::in(array_keys(config('field_execution.photo_categories')))],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);
        $disk = config('field_execution.disk');
        $key = $data['photo']->store('field-media/'.now()->format('Y/m'), $disk);
        if (! $key) {
            return response()->json(['message' => 'Upload failed.'], 500);
        }
        try {
            $media = VisitMedia::query()->create([
                'organization_id' => $visit->organization_id,
                'visit_id' => $visit->id,
                'closeout_id' => $closeout->id,
                'uploader_id' => $request->user()->id,
                'storage_disk' => $disk,
                'storage_key' => $key,
                'mime_type' => $data['photo']->getMimeType(),
                'byte_size' => $data['photo']->getSize(),
                'category' => $data['category'],
                'caption' => $data['caption'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($key);
            throw $exception;
        }
        $audit->record($request->attributes->get('organization'), $request->user(), 'visit_media.uploaded', $media, [
            'visit_id' => $visit->id,
            'category' => $media->category,
            'byte_size' => $media->byte_size,
        ]);

        return response()->json(['message' => 'Photo uploaded.', 'id' => $media->id], 201);
    }

    public function media(Request $request, string $media): StreamedResponse
    {
        $media = VisitMedia::query()->where('organization_id', $request->attributes->get('organization')->id)->where('state', 'stored')->findOrFail($media);
        abort_unless($request->attributes->get('membership')->hasCapability('closeouts.manual_complete'), 403);

        return Storage::disk($media->storage_disk)->response($media->storage_key, null, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function removeMedia(Request $request, string $visit, string $media, AdminManualCloseoutWorkflow $workflow, AuditRecorder $audit): RedirectResponse
    {
        $visit = $this->visit($request, $visit);
        $workflow->start($visit, $request->user());
        $media = VisitMedia::query()->where('visit_id', $visit->id)->where('closeout_id', $visit->current_closeout_id)->where('state', 'stored')->findOrFail($media);
        $media->update(['state' => 'removed', 'removed_at' => now(), 'removed_by_id' => $request->user()->id]);
        $audit->record($request->attributes->get('organization'), $request->user(), 'visit_media.removed', $media, ['visit_id' => $visit->id]);
        DeleteRemovedVisitMedia::dispatch($media->id)->afterCommit();

        return $this->backToModal($visit, 'Photo removed.');
    }

    /** @return array<string, mixed> */
    private function closeoutData(Request $request): array
    {
        return $request->validate([
            'content_version' => ['required', 'integer', 'min:1'],
            'diagnosis' => ['nullable', 'string', 'max:10000'],
            'work_performed' => ['nullable', 'string', 'max:10000'],
            'result_summary' => ['nullable', 'string', 'max:10000'],
            'exceptions' => ['nullable', 'string', 'max:10000'],
            'recommendations' => ['nullable', 'string', 'max:10000'],
            'representative_name' => ['nullable', 'string', 'max:255'],
            'ack_unavailable_category' => ['nullable', Rule::in(array_keys(config('field_execution.ack_fallbacks')))],
            'ack_unavailable_detail' => ['nullable', 'string', 'max:5000'],
            'no_photo_category' => ['nullable', Rule::in(array_keys(config('field_execution.no_photo_reasons')))],
            'no_photo_detail' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function visit(Request $request, string $id): Visit
    {
        $organization = $request->attributes->get('organization');
        $visit = Visit::query()->forOrganization($organization->id)->find($id);
        if (! $visit) {
            if (Visit::withTrashed()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                    'record_type' => 'manual_closeout_visit',
                    'record_id' => (int) $id,
                ]);
            }
            abort(404);
        }

        return $visit;
    }

    private function backToModal(Visit $visit, ?string $status = null): RedirectResponse
    {
        $response = redirect()->to(route('office.service-tickets.show', $visit->service_ticket_id).'?manual_closeout_visit='.$visit->id);

        return $status ? $response->with('status', $status) : $response;
    }
}
