<?php

namespace App\Http\Controllers\Office;

use App\Domain\FieldTestServiceTicketPurgePreview;
use App\Domain\FieldTestServiceTicketPurger;
use App\Exceptions\FieldTestPurgeStorageCleanupException;
use App\Http\Controllers\Controller;
use App\Models\FieldTestPurgeCleanup;
use App\Models\ServiceTicket;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class FieldTestServiceTicketPurgeController extends Controller
{
    public function confirm(Request $request, string $serviceTicket, FieldTestServiceTicketPurgePreview $preview): View
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('purgeTestData', $ticket);

        return view('office.service-tickets.field-test-purge', [
            'ticket' => $ticket->load(['customer', 'serviceLocation']),
            'preview' => $preview->build($ticket),
        ]);
    }

    public function destroy(Request $request, string $serviceTicket, FieldTestServiceTicketPurger $purger): RedirectResponse
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('purgeTestData', $ticket);
        $data = $request->validate([
            'ticket_number' => ['required', 'string', 'max:40'],
            'acknowledge' => ['required', 'accepted'],
        ]);

        try {
            $purger->purge($ticket, $request->user(), $data['ticket_number'], $request->boolean('acknowledge'));
        } catch (FieldTestPurgeStorageCleanupException $exception) {
            return redirect()->route('office.field-test-purge-cleanups.show', $exception->cleanupPublicId)
                ->with('error', 'Database purge complete; private storage cleanup is incomplete. Retry cleanup below.');
        }

        return redirect()->route('office.service-tickets.index')
            ->with('status', 'The field-test Service Ticket and its owned Portal records were permanently purged.');
    }

    public function showCleanup(Request $request, string $cleanup): View
    {
        $cleanup = $this->cleanup($request, $cleanup);

        return view('office.service-tickets.field-test-purge-cleanup', compact('cleanup'));
    }

    public function retryCleanup(Request $request, string $cleanup, FieldTestServiceTicketPurger $purger): RedirectResponse
    {
        $cleanup = $this->cleanup($request, $cleanup);
        try {
            $purger->retryCleanup($cleanup);
        } catch (FieldTestPurgeStorageCleanupException) {
            return back()->with('error', 'Private storage cleanup is still incomplete. No relational records were restored or changed.');
        }

        return redirect()->route('office.service-tickets.index')->with('status', 'Private storage cleanup completed.');
    }

    private function ticket(Request $request, string $id): ServiceTicket
    {
        $this->assertEnabled();
        $organization = $request->attributes->get('organization');
        $ticket = ServiceTicket::query()->forOrganization($organization->id)->find($id);
        if (! $ticket) {
            if (ServiceTicket::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                    'record_type' => 'field_test_service_ticket_purge',
                    'record_id' => (int) $id,
                ]);
            }
            abort(404);
        }

        return $ticket;
    }

    private function cleanup(Request $request, string $publicId): FieldTestPurgeCleanup
    {
        $this->assertEnabled();
        $organization = $request->attributes->get('organization');
        $membership = $request->attributes->get('membership');
        abort_unless($membership->roles()->where('key', 'super_admin')->exists()
            && $membership->hasCapability('service_tickets.purge_test_data'), 403);

        return FieldTestPurgeCleanup::query()
            ->where('organization_id', $organization->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function assertEnabled(): void
    {
        abort_unless(config('field_test.destructive_service_ticket_purge_enabled'), 404);
    }
}
