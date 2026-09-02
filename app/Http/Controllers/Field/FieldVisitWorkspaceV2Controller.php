<?php

namespace App\Http\Controllers\Field;

use App\Domain\CloseoutReadiness;
use App\Http\Controllers\Controller;
use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\Closeout;
use App\Models\Visit;
use App\Support\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class FieldVisitWorkspaceV2Controller extends Controller
{
    public function __invoke(Request $request, string $visit, CloseoutReadiness $readiness): View
    {
        $visit = $this->visit($request, $visit);
        Gate::authorize('view', $visit);
        $visit->load([
            'serviceTicket.customer',
            'serviceTicket.contact',
            'serviceTicket.returnFollowUpSourceTicket',
            'serviceTicket.returnFollowUpSourceCloseout.submittedBy',
            'serviceTicket.workItems' => fn ($query) => $query->with(['discoveredVisit.returnOfVisit', 'visits.returnOfVisit', 'followUpServiceTicket'])->orderBy('id'),
            'serviceTicket.invoices' => fn ($query) => $query->where('status', 'issued')->latest('issued_at'),
            'serviceTicket.visits' => fn ($query) => $query->select(['id', 'service_ticket_id', 'ticket_visit_number', 'return_of_visit_id', 'status', 'scheduled_start_at', 'timezone'])->with('returnOfVisit:id,ticket_visit_number')->orderBy('ticket_visit_number'),
            'serviceLocation.primaryContact',
            'returnOfVisit:id,ticket_visit_number',
            'assignments.membership.user',
            'currentCloseout.lastSavedBy',
            'currentCloseout.timeEntries.user',
            'currentCloseout.timeEntries.workItem',
            'currentCloseout.timeEntries.allocationSets.allocations.workItem',
            'currentCloseout.media',
            'currentCloseout.parts',
            'currentCloseout.acknowledgmentSignature',
            'currentCloseout.parent.reviews.reviewer',
            'workItems.followUpServiceTicket',
        ]);

        $versions = Closeout::query()->where('visit_id', $visit->id)->where('organization_id', $visit->organization_id)
            ->with(['reviews.reviewer', 'media', 'parts', 'acknowledgmentSignature'])->orderBy('version')->get();

        [$catalogServices, $catalogProducts, $catalogPackages] = $this->catalog($request, $visit);
        $closeoutReadinessErrors = $visit->currentCloseout
            ? $readiness->errors($visit->currentCloseout, false, true)
            : ['outcome' => 'Choose an outcome.'];

        return view('field.visits.workspace-v2', compact(
            'visit', 'versions', 'catalogServices', 'catalogProducts', 'catalogPackages', 'closeoutReadinessErrors'
        ));
    }

    private function catalog(Request $request, Visit $visit): array
    {
        if (! $request->attributes->get('membership')->hasCapability('catalog.use')) {
            return [collect(), collect(), collect()];
        }

        return [
            CatalogService::query()->forOrganization($visit->organization_id)->where('active', true)->with(['salesUom', 'variants' => fn ($query) => $query->where('active', true)])->orderBy('name')->get(),
            CatalogProduct::query()->forOrganization($visit->organization_id)->where('active', true)->with('defaultSalesUom')->orderBy('name')->get(),
            CatalogPackage::query()->forOrganization($visit->organization_id)->where('active', true)->with('salesUom')->orderBy('name')->get(),
        ];
    }

    private function visit(Request $request, string $id): Visit
    {
        $organization = $request->attributes->get('organization');
        $visit = Visit::query()->forOrganization($organization->id)->find($id);
        if (! $visit) {
            if (Visit::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                    'record_type' => 'visit',
                    'record_id' => (int) $id,
                ]);
            }
            abort(404);
        }

        return $visit;
    }
}
