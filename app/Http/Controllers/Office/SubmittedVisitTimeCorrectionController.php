<?php

namespace App\Http\Controllers\Office;

use App\Domain\SubmittedVisitTimeCorrection;
use App\Http\Controllers\Controller;
use App\Models\Closeout;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use App\Support\ScheduleWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubmittedVisitTimeCorrectionController extends Controller
{
    public function update(
        Request $request,
        string $entry,
        SubmittedVisitTimeCorrection $correction,
        ScheduleWindow $windows,
    ): RedirectResponse {
        $organization = $request->attributes->get('organization');
        $entry = VisitTimeEntry::query()
            ->where('organization_id', $organization->id)
            ->with(['visit.serviceTicket', 'closeout'])
            ->find($entry);
        if (! $entry) {
            if (VisitTimeEntry::query()->whereKey((int) $request->route('entry'))->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                    'record_type' => 'visit_time_entry',
                    'record_id' => (int) $request->route('entry'),
                ]);
            }
            abort(404);
        }

        $data = $request->validate([
            'context' => ['required', Rule::in(['ticket', 'review'])],
            'review_closeout_id' => ['nullable', 'integer'],
            'started_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ended_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $reviewCloseout = null;
        if ($data['context'] === 'review') {
            $reviewCloseout = Closeout::query()
                ->where('organization_id', $organization->id)
                ->where('visit_id', $entry->visit_id)
                ->findOrFail($data['review_closeout_id'] ?? 0);
        }
        $window = $windows->fromLocal($data['started_at'], $data['ended_at'], $entry->visit->timezone);
        $correction->correct($entry, $request->user(), $window['start'], $window['end'], $data['reason']);

        if ($reviewCloseout) {
            return redirect()->route('office.closeout-reviews.show', $reviewCloseout)
                ->with('status', 'Submitted Visit time corrected.');
        }

        return redirect()->to(route('office.service-tickets.show', $entry->visit->service_ticket_id).'?execution_visit='.$entry->visit_id)
            ->with('status', 'Submitted Visit time corrected.');
    }
}
