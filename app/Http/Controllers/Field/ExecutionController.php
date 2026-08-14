<?php

namespace App\Http\Controllers\Field;

use App\Domain\CatalogLineSnapshotFactory;
use App\Domain\CloseoutReadiness;
use App\Domain\FieldExecution;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteRemovedVisitMedia;
use App\Models\Closeout;
use App\Models\Visit;
use App\Models\VisitMedia;
use App\Models\VisitPartProposal;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use App\Support\ScheduleWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExecutionController extends Controller
{
    private function visit(Request $r, string $id): Visit
    {
        $organization = $r->attributes->get('organization');
        $visit = Visit::forOrganization($organization->id)->find($id);
        if (! $visit) {
            if (Visit::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $r->user(), 'security.cross_organization_record_denied', $organization, ['record_type' => 'visit', 'record_id' => (int) $id]);
            }
            abort(404);
        }

        return $visit;
    }

    private function authorized(Request $r, string $id): Visit
    {
        $v = $this->visit($r, $id);
        Gate::authorize('execute', $v);

        return $v;
    }

    private function writable(Request $request, string $id): Visit
    {
        $visit = $this->authorized($request, $id);
        if ($visit->status === 'canceled') {
            throw ValidationException::withMessages(['visit' => 'Canceled visits are read-only.']);
        }

        return $visit;
    }

    public function save(Request $r, string $visit, FieldExecution $flow): RedirectResponse|Response
    {
        $v = $this->writable($r, $visit);
        $c = $flow->draft($v, $r->user());
        $d = $r->validate(['content_version' => 'required|integer', 'outcome' => ['nullable', Rule::in(['resolved', 'needs_return_trip', 'customer_unavailable', 'on_hold'])], 'diagnosis' => 'nullable|string|max:10000', 'work_performed' => 'nullable|string|max:10000', 'exceptions' => 'nullable|string|max:10000', 'recommendations' => 'nullable|string|max:10000', 'return_reason' => 'nullable|string|max:5000', 'unfinished_work' => 'nullable|string|max:5000', 'needed_equipment' => 'nullable|string|max:5000', 'hold_reason' => 'nullable|string|max:5000', 'unavailable_category' => ['nullable', Rule::in(array_keys(config('field_execution.unavailable_reasons')))], 'unavailable_detail' => 'nullable|string|max:5000', 'representative_name' => 'nullable|string|max:255', 'ack_unavailable_category' => ['nullable', Rule::in(array_keys(config('field_execution.ack_fallbacks')))], 'ack_unavailable_detail' => 'nullable|string|max:5000', 'no_photo_category' => ['nullable', Rule::in(array_keys(config('field_execution.no_photo_reasons')))], 'no_photo_detail' => 'nullable|string|max:5000']);
        $version = (int) $d['content_version'];
        unset($d['content_version']);
        if (! $flow->save($c, $d, $version, $r->user())) {
            $r->flash();
            $v->load([
                'serviceTicket.customer', 'serviceTicket.contact',
                'serviceTicket.visits' => fn ($query) => $query->select(['id', 'service_ticket_id', 'ticket_visit_number', 'return_of_visit_id', 'status', 'scheduled_start_at', 'timezone'])->with('returnOfVisit:id,ticket_visit_number')->orderBy('ticket_visit_number'),
                'serviceLocation.primaryContact', 'assignments.membership.user',
                'currentCloseout.lastSavedBy', 'currentCloseout.timeEntries.user', 'currentCloseout.media', 'currentCloseout.parts',
            ]);

            $versions = Closeout::query()->where('visit_id', $v->id)->where('organization_id', $v->organization_id)->with(['reviews.reviewer', 'media', 'parts'])->orderBy('version')->get();

            return response()->view('field.visits.show', [
                'visit' => $v,
                'versions' => $versions,
                'draftConflict' => true,
                'closeoutReadinessErrors' => app(CloseoutReadiness::class)->errors($v->currentCloseout),
            ], 409);
        }

        return back()->with('status', 'Draft saved.');
    }

    public function timer(Request $r, string $visit, FieldExecution $flow): RedirectResponse
    {
        $v = $this->writable($r, $visit);
        $c = $flow->draft($v, $r->user());
        $d = $r->validate(['action' => 'required|in:start,stop', 'category' => 'nullable|in:travel,on_site,other']);
        $active = VisitTimeEntry::where('active_user_id', $r->user()->id)->first();
        if ($d['action'] === 'stop') {
            if (! $active || $active->visit_id !== $v->id) {
                return back()->withErrors(['time' => 'No timer is running for this visit.']);
            }$flow->stopTimer($active, $r->user());
        } else {
            if ($active) {
                return back()->withErrors(['time' => 'Stop your active timer first.']);
            }$flow->startTimer($v, $c, $r->user(), $d['category'] ?? 'other');
        }

        return back()->with('status', 'Time updated.');
    }

    public function updateTime(Request $r, string $visit, string $entry, AuditRecorder $audit, ScheduleWindow $scheduleWindow): RedirectResponse
    {
        $v = $this->authorized($r, $visit);
        $e = VisitTimeEntry::where('visit_id', $v->id)->where('user_id', $r->user()->id)->findOrFail($entry);
        $d = $r->validate(['started_at' => 'required|date_format:Y-m-d\TH:i', 'ended_at' => 'required|date_format:Y-m-d\TH:i', 'correction_reason' => 'required|string|max:1000']);
        $window = $scheduleWindow->fromLocal($d['started_at'], $d['ended_at'], $v->timezone);
        app(FieldExecution::class)->correctTime($e, $r->user(), $window['start'], $window['end'], $d['correction_reason']);

        return back()->with('status', 'Time correction saved.');
    }

    public function addPart(Request $r, string $visit, FieldExecution $flow): RedirectResponse
    {
        $v = $this->writable($r, $visit);
        $c = $flow->draft($v, $r->user());
        abort_if($c->status !== 'draft', 422);
        $d = $r->validate(['description' => 'required|string|max:255', 'quantity' => 'required|numeric|min:0.01|max:999999', 'unit' => 'nullable|string|max:40', 'serial_mac' => 'nullable|string|max:255', 'billing_treatment' => ['required', Rule::in(array_keys(config('field_execution.billing_treatments')))], 'technician_note' => 'nullable|string|max:5000']);
        $part = VisitPartProposal::create($d + ['organization_id' => $v->organization_id, 'visit_id' => $v->id, 'closeout_id' => $c->id, 'proposed_by_id' => $r->user()->id]);
        app(AuditRecorder::class)->record($r->attributes->get('organization'), $r->user(), 'visit_part_proposal.created', $part, ['visit_id' => $v->id]);

        return back()->with('status', 'Part proposal added.');
    }

    public function addCatalogItem(Request $request, string $visit, FieldExecution $flow, CatalogLineSnapshotFactory $snapshots): RedirectResponse
    {
        abort_unless($request->attributes->get('membership')->hasCapability('catalog.use'), 403);
        $visit = $this->writable($request, $visit);
        $closeout = $flow->draft($visit, $request->user());
        abort_if($closeout->status !== 'draft', 422);
        $data = $request->validate([
            'catalog_item' => ['required', 'regex:/^(service|product|package):\d+$/'],
            'catalog_service_variant_id' => ['nullable', 'integer'],
            'catalog_quantity' => ['required', 'regex:/^\d{1,7}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'billing_treatment' => ['required', Rule::in(array_keys(config('field_execution.billing_treatments')))],
            'technician_note' => ['nullable', 'string', 'max:5000'],
        ]);
        [$type, $itemId] = explode(':', $data['catalog_item'], 2);
        $quantityMillis = $this->decimalToMillis($data['catalog_quantity']);
        $snapshot = $snapshots->create($visit->organization_id, $type, (int) $itemId, $quantityMillis, filled($data['catalog_service_variant_id'] ?? null) ? (int) $data['catalog_service_variant_id'] : null);
        $proposal = VisitPartProposal::query()->create($snapshot + [
            'organization_id' => $visit->organization_id,
            'visit_id' => $visit->id,
            'closeout_id' => $closeout->id,
            'proposed_by_id' => $request->user()->id,
            'description' => $snapshot['catalog_name_snapshot'],
            'quantity' => number_format($quantityMillis / 1000, 2, '.', ''),
            'unit' => $snapshot['catalog_unit_name_snapshot'],
            'billing_treatment' => $data['billing_treatment'],
            'technician_note' => $data['technician_note'] ?? null,
            'catalog_selected_by_id' => $request->user()->id,
            'catalog_selected_at' => now(),
        ]);
        app(AuditRecorder::class)->record($request->attributes->get('organization'), $request->user(), 'catalog.field_item_selected', $proposal, [
            'visit_id' => $visit->id,
            'closeout_id' => $closeout->id,
            'proposal_id' => $proposal->id,
            'catalog_item_type' => $type,
            'catalog_source_id' => (int) $itemId,
            'catalog_variant_id' => $snapshot['catalog_service_variant_id'] ?? null,
            'quantity_millis' => $quantityMillis,
        ]);

        return back()->with('status', 'Catalog item added to the closeout proposal.');
    }

    public function removePart(Request $r, string $visit, string $part): RedirectResponse
    {
        $v = $this->writable($r, $visit);
        $p = VisitPartProposal::where('visit_id', $v->id)->findOrFail($part);
        abort_if($p->closeout_id !== $v->current_closeout_id || $v->currentCloseout->status !== 'draft', 422);
        $p->update(['removed_at' => now()]);
        app(AuditRecorder::class)->record($r->attributes->get('organization'), $r->user(), 'visit_part_proposal.removed', $p, ['visit_id' => $v->id]);

        return back()->with('status', 'Part proposal removed.');
    }

    public function upload(Request $r, string $visit, FieldExecution $flow, AuditRecorder $audit): JsonResponse
    {
        $v = $this->writable($r, $visit);
        $c = $flow->draft($v, $r->user());
        abort_if($c->status !== 'draft', 422);
        if ($c->media()->where('state', 'stored')->count() >= config('field_execution.max_photos')) {
            return response()->json(['message' => 'Photo limit reached.'], 422);
        }$d = $r->validate(['photo' => ['required', 'file', 'max:'.config('field_execution.max_photo_kb'), 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif'], 'category' => ['required', Rule::in(array_keys(config('field_execution.photo_categories')))], 'caption' => 'nullable|string|max:255']);
        $disk = config('field_execution.disk');
        $key = $d['photo']->store('field-media/'.now()->format('Y/m'), $disk);
        if (! $key) {
            return response()->json(['message' => 'Upload failed.'], 500);
        }
        try {
            $m = VisitMedia::create(['organization_id' => $v->organization_id, 'visit_id' => $v->id, 'closeout_id' => $c->id, 'uploader_id' => $r->user()->id, 'storage_disk' => $disk, 'storage_key' => $key, 'mime_type' => $d['photo']->getMimeType(), 'byte_size' => $d['photo']->getSize(), 'category' => $d['category'], 'caption' => $d['caption'] ?? null]);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($key);
            throw $exception;
        }
        $audit->record($r->attributes->get('organization'), $r->user(), 'visit_media.uploaded', $m, ['visit_id' => $v->id, 'category' => $m->category, 'byte_size' => $m->byte_size]);

        return response()->json(['message' => 'Photo uploaded.', 'id' => $m->id], 201);
    }

    public function media(Request $r, string $media): StreamedResponse
    {
        $m = VisitMedia::where('organization_id', $r->attributes->get('organization')->id)->where('state', 'stored')->findOrFail($media);
        Gate::authorize('view', $m->visit);
        if ($m->closeout->status === 'draft' && ! Gate::allows('execute', $m->visit)) {
            abort(403);
        }
        if (! Gate::allows('execute', $m->visit) && ! $r->attributes->get('membership')->hasCapability('closeouts.inspect')) {
            abort(403);
        }

        return Storage::disk($m->storage_disk)->response($m->storage_key, null, ['Content-Type' => $m->mime_type, 'Cache-Control' => 'private, no-store']);
    }

    public function removeMedia(Request $r, string $visit, string $media, AuditRecorder $audit): RedirectResponse
    {
        $v = $this->writable($r, $visit);
        $m = VisitMedia::where('visit_id', $v->id)->where('state', 'stored')->findOrFail($media);
        abort_if($m->closeout->status !== 'draft', 422);
        $m->update(['state' => 'removed', 'removed_at' => now(), 'removed_by_id' => $r->user()->id]);
        $audit->record($r->attributes->get('organization'), $r->user(), 'visit_media.removed', $m, ['visit_id' => $v->id]);
        DeleteRemovedVisitMedia::dispatch($m->id)->afterCommit();

        return back()->with('status', 'Photo removed.');
    }

    public function submit(Request $r, string $visit, FieldExecution $flow): RedirectResponse
    {
        $v = $this->writable($r, $visit);
        $c = $v->currentCloseout ?: $flow->draft($v, $r->user());
        $d = $r->validate(['submission_token' => 'required|uuid', 'acknowledgment_confirmed' => 'sometimes|accepted']);
        if (filled($c->representative_name) && ! $r->boolean('acknowledgment_confirmed')) {
            return back()->withErrors(['acknowledgment_confirmed' => 'Confirm the customer acknowledgment before submitting.']);
        }$flow->submit($v, $c, $r->user(), $d['submission_token']);

        return back()->with('status', 'Closeout submitted for office review.');
    }

    private function decimalToMillis(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad(substr($decimal, 0, 3), 3, '0');
    }
}
