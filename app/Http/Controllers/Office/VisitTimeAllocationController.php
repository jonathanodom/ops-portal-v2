<?php

namespace App\Http\Controllers\Office;

use App\Domain\VisitTimeAllocationWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Closeout;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class VisitTimeAllocationController extends Controller
{
    public function store(Request $request, string $entry, VisitTimeAllocationWorkflow $workflow): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $entry = VisitTimeEntry::query()->where('organization_id', $organization->id)->with('visit')->find($entry);
        if (! $entry) {
            if (VisitTimeEntry::query()->whereKey((int) $request->route('entry'))->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization,
                    ['record_type' => 'visit_time_entry', 'record_id' => (int) $request->route('entry')]);
            }
            abort(404);
        }
        $data = $request->validate([
            'context' => ['required', Rule::in(['ticket', 'review'])],
            'review_closeout_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.target' => ['required', 'string', 'regex:/^(primary|item:\d+)$/'],
            'allocations.*.minutes' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
        ]);
        $rows = collect($data['allocations'])->map(function (array $row): array {
            $seconds = $this->minutesToSeconds($row['minutes']);

            return ['work_item_id' => $row['target'] === 'primary' ? null : (int) substr($row['target'], 5), 'allocated_seconds' => $seconds];
        })->filter(fn (array $row): bool => $row['allocated_seconds'] > 0)->values()->all();
        $workflow->allocate($entry, $request->user(), $rows, $data['reason']);
        if ($data['context'] === 'review') {
            $closeout = Closeout::query()->where('organization_id', $organization->id)->where('visit_id', $entry->visit_id)
                ->findOrFail($data['review_closeout_id'] ?? 0);

            return redirect()->route('office.closeout-reviews.show', $closeout)->with('status', 'Work time allocation saved.');
        }

        return redirect()->to(route('office.service-tickets.show', $entry->visit->service_ticket_id).'?execution_visit='.$entry->visit_id)
            ->with('status', 'Work time allocation saved.');
    }

    private function minutesToSeconds(string $minutes): int
    {
        [$whole, $fraction] = array_pad(explode('.', $minutes, 2), 2, '');
        $hundredths = (int) str_pad(substr($fraction, 0, 2), 2, '0');
        $seconds = ((int) $whole * 60) + intdiv(($hundredths * 60) + 50, 100);

        return $seconds;
    }
}
