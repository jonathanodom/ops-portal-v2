<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\OperationalIncident;
use App\Support\AuditRecorder;
use App\Support\OperationalHealthScan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OperationalHealthController extends Controller
{
    public function index(Request $request, OperationalHealthScan $scan): View
    {
        $organization = $request->attributes->get('organization');
        $scan->scan($organization);
        $scope = fn ($query) => $query->where(fn ($query) => $query->where('organization_id', $organization->id)->orWhereNull('organization_id'));
        $incidents = OperationalIncident::query()->where($scope)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', $request->string('severity')))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->with(['actor', 'resolvedBy'])->latest('last_occurred_at')->paginate(30)->withQueryString();

        return view('office.operations.health', [
            'incidents' => $incidents,
            'categories' => OperationalIncident::query()->where($scope)->distinct()->orderBy('category')->pluck('category'),
            'failedJobs' => DB::table('failed_jobs')->count(),
        ]);
    }

    public function resolve(Request $request, string $incident, AuditRecorder $audit): RedirectResponse
    {
        return $this->changeStatus($request, $incident, 'resolved', $audit);
    }

    public function reopen(Request $request, string $incident, AuditRecorder $audit): RedirectResponse
    {
        return $this->changeStatus($request, $incident, 'open', $audit);
    }

    private function changeStatus(Request $request, string $id, string $status, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $incident = OperationalIncident::query()->where(fn ($query) => $query->where('organization_id', $organization->id)->orWhereNull('organization_id'))->findOrFail($id);
        $incident->update([
            'status' => $status,
            'resolved_by_id' => $status === 'resolved' ? $request->user()->id : null,
            'resolved_at' => $status === 'resolved' ? now() : null,
        ]);
        $audit->record($organization, $request->user(), 'operational_incident.'.$status, $incident, [
            'incident_id' => $incident->id,
            'category' => $incident->category,
            'status' => $status,
        ]);

        return back()->with('status', $status === 'resolved' ? 'Incident resolved.' : 'Incident reopened.');
    }
}
