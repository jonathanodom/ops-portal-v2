<?php

namespace App\Http\Controllers\Office;

use App\Domain\CustomerServiceEnrollmentWorkflow;
use App\Http\Controllers\Controller;
use App\Models\CatalogService;
use App\Models\Customer;
use App\Models\CustomerServiceEnrollment;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerServiceEnrollmentController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [CustomerServiceEnrollment::class, $organization]);
        $enrollments = CustomerServiceEnrollment::query()->forOrganization($organization->id)
            ->with(['customer', 'serviceLocation'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = '%'.$request->string('q')->value().'%';
                $query->where(fn ($query) => $query->where('service_name_snapshot', 'like', $search)
                    ->orWhere('service_code_snapshot', 'like', $search)
                    ->orWhereHas('customer', fn ($query) => $query->where('display_name', 'like', $search))
                    ->orWhereHas('serviceLocation', fn ($query) => $query->where('name', 'like', $search)));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByRaw("case status when 'active' then 1 when 'paused' then 2 else 3 end")
            ->orderBy('next_billing_date')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('office.subscriptions.index', compact('enrollments'));
    }

    public function create(Request $request, string $customer): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CustomerServiceEnrollment::class, $organization]);
        $customer = Customer::query()->forOrganization($organization->id)->findOrFail($customer);
        $services = CatalogService::query()->forOrganization($organization->id)->where('active', true)
            ->where('pricing_model', 'recurring')->with(['salesUom', 'variants' => fn ($query) => $query->where('active', true)])->orderBy('name')->get();
        $locations = $customer->serviceLocations()->where('active', true)->orderByDesc('is_primary')->orderBy('name')->get();

        return view('office.subscriptions.create', compact('customer', 'services', 'locations'));
    }

    public function store(Request $request, string $customer, CustomerServiceEnrollmentWorkflow $workflow): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CustomerServiceEnrollment::class, $organization]);
        $customer = Customer::query()->forOrganization($organization->id)->findOrFail($customer);
        $data = $request->validate([
            'catalog_service_id' => ['required', 'integer'],
            'catalog_service_variant_id' => ['nullable', 'integer'],
            'service_location_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'next_billing_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'billing_amount' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'billing_amount_reason' => ['nullable', 'string', 'max:1000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        if (filled($data['billing_amount'] ?? null) && blank($data['billing_amount_reason'] ?? null)) {
            throw ValidationException::withMessages(['billing_amount_reason' => 'A reason is required when overriding the recurring Catalog amount.']);
        }
        $data['billing_amount_cents'] = filled($data['billing_amount'] ?? null) ? $this->dollarsToCents($data['billing_amount']) : null;
        $data['billing_amount_override_reason'] = $data['billing_amount_reason'] ?? null;
        unset($data['billing_amount'], $data['billing_amount_reason']);
        $enrollment = $workflow->create($customer, $request->user(), $data);

        return redirect()->route('office.subscriptions.show', $enrollment)->with('status', 'Recurring Service enrollment created. No invoice or payment was created.');
    }

    public function show(Request $request, string $enrollment): View
    {
        $enrollment = $this->enrollment($request, $enrollment);
        Gate::authorize('view', $enrollment);
        $enrollment->load(['customer', 'serviceLocation', 'catalogService', 'catalogServiceVariant']);

        return view('office.subscriptions.show', compact('enrollment'));
    }

    public function update(Request $request, string $enrollment, CustomerServiceEnrollmentWorkflow $workflow): RedirectResponse
    {
        $enrollment = $this->enrollment($request, $enrollment);
        Gate::authorize('update', $enrollment);
        if ($enrollment->status === 'canceled') {
            throw ValidationException::withMessages(['status' => 'Canceled enrollments are immutable. Create a new enrollment to restart this Service.']);
        }
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'next_billing_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'billing_amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'billing_amount_reason' => ['nullable', 'string', 'max:1000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $amount = $this->dollarsToCents($data['billing_amount']);
        if ($amount !== (int) $enrollment->billing_amount_cents && blank($data['billing_amount_reason'] ?? null)) {
            throw ValidationException::withMessages(['billing_amount_reason' => 'A reason is required to change the enrollment amount.']);
        }
        $workflow->update($enrollment, $request->user(), [
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'next_billing_date' => $data['next_billing_date'] ?? null,
            'billing_amount_cents' => $amount,
            'billing_amount_override_reason' => $amount !== (int) $enrollment->billing_amount_cents ? $data['billing_amount_reason'] : $enrollment->billing_amount_override_reason,
            'internal_notes' => $data['internal_notes'] ?? null,
        ]);

        return back()->with('status', 'Enrollment details saved.');
    }

    public function transition(Request $request, string $enrollment, CustomerServiceEnrollmentWorkflow $workflow): RedirectResponse
    {
        $enrollment = $this->enrollment($request, $enrollment);
        Gate::authorize('update', $enrollment);
        $data = $request->validate([
            'status' => ['required', Rule::in(CustomerServiceEnrollment::STATUSES)],
            'confirmation' => ['required', 'accepted'],
        ]);
        $workflow->transition($enrollment, $request->user(), $data['status']);

        return back()->with('status', 'Enrollment status changed to '.str_replace('_', ' ', $data['status']).'.');
    }

    private function enrollment(Request $request, string $id): CustomerServiceEnrollment
    {
        $organization = $request->attributes->get('organization');
        $enrollment = CustomerServiceEnrollment::query()->forOrganization($organization->id)->find($id);
        if (! $enrollment && CustomerServiceEnrollment::query()->whereKey($id)->exists()) {
            app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                'record_type' => 'customer_service_enrollment',
                'record_id' => (int) $id,
            ]);
        }

        return $enrollment ?? abort(404);
    }

    private function dollarsToCents(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
    }
}
