<?php

namespace App\Http\Controllers\Office;

use App\Domain\ApprovedVisitLaborWorkflow;
use App\Domain\CatalogLineSnapshotFactory;
use App\Domain\InvoiceWorkflow;
use App\Domain\UnissuedInvoiceDeletionWorkflow;
use App\Http\Controllers\Controller;
use App\Jobs\RenderInvoicePdf;
use App\Models\AuditEvent;
use App\Models\BillingHandoff;
use App\Models\BillingLaborRate;
use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\OrganizationBillingSetting;
use App\Models\PaymentProviderConfiguration;
use App\Models\ServiceTicket;
use App\Models\Visit;
use App\Models\VisitPartProposal;
use App\Support\AuditRecorder;
use App\Support\CustomerDirectorySearch;
use App\Support\CustomerSelection;
use App\Support\InvoiceBalanceExpressions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function create(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [Invoice::class, $organization]);
        $selectedCustomer = null;
        if ($request->old('customer_id')) {
            $selectedCustomer = Customer::query()->forOrganization($organization->id)
                ->where('status', 'active')
                ->with([
                    'serviceLocations' => fn ($query) => $query->where('active', true)->orderByDesc('is_primary')->orderBy('name'),
                    'contacts' => fn ($query) => $query->where('active', true)->orderByDesc('is_preferred')->orderBy('name'),
                ])
                ->find($request->old('customer_id'));
        }

        return view('office.invoices.create', compact('selectedCustomer'));
    }

    public function customerOptions(Request $request, CustomerDirectorySearch $directory, CustomerSelection $selection): JsonResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [Invoice::class, $organization]);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
        $customers = $directory->ticketOptions($organization, $data['q'] ?? '')
            ->map(fn (Customer $customer): array => $selection->present($customer));

        return response()->json(['customers' => $customers])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, InvoiceWorkflow $workflow): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [Invoice::class, $organization]);
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'service_location_id' => ['required', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'creation_token' => ['required', 'uuid'],
        ]);
        $invoice = $workflow->createDirect(
            $organization,
            (int) $data['customer_id'],
            (int) $data['service_location_id'],
            isset($data['contact_id']) ? (int) $data['contact_id'] : null,
            $request->user(),
            $data['creation_token'],
        );

        return redirect()->route('office.invoices.show', $invoice)->with('status', 'Direct invoice draft created. Add invoice items, then review billing details.');
    }

    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [Invoice::class, $organization]);
        $filters = $request->validate([
            'invoice' => ['nullable', 'string', 'max:100'],
            'customer' => ['nullable', 'string', 'max:255'],
            'ticket' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'ready_for_review', 'issued', 'void'])],
            'payment_state' => ['nullable', Rule::in(['unpaid', 'partially_paid', 'paid', 'partially_refunded', 'refunded', 'overpaid'])],
            'balance_state' => ['nullable', Rule::in(['open', 'paid', 'overdue'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(['date', 'invoice', 'customer', 'ticket', 'status', 'due', 'total', 'balance'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'workspace' => ['nullable', Rule::in(['all', 'ready_to_invoice', 'draft', 'ready_for_review', 'issued', 'paid', 'void', 'overdue'])],
        ]);
        $paid = InvoiceBalanceExpressions::paid();
        $refunded = InvoiceBalanceExpressions::refunded();
        $net = InvoiceBalanceExpressions::net();
        $balance = InvoiceBalanceExpressions::balance();

        $query = Invoice::query()->forOrganization($organization->id)
            ->with(['customer', 'serviceTicket', 'serviceLocation', 'paymentTransactions']);

        match ($filters['workspace'] ?? 'all') {
            'ready_to_invoice' => $query->whereRaw('1 = 0'),
            'draft', 'ready_for_review', 'issued', 'void' => $query->where('status', $filters['workspace']),
            'paid' => $query->whereRaw("{$balance} <= 0")->where('status', 'issued'),
            'overdue' => $query->where('status', 'issued')->whereNotNull('due_on')->whereDate('due_on', '<', now($organization->timezone)->toDateString())->whereRaw("{$balance} > 0"),
            default => null,
        };

        $query->when(filled($filters['invoice'] ?? null), fn ($query) => $query->where('invoice_number', 'like', '%'.$filters['invoice'].'%'))
            ->when(filled($filters['customer'] ?? null), fn ($query) => $query->whereHas('customer', fn ($customer) => $customer->where(fn ($names) => $names->where('display_name', 'like', '%'.$filters['customer'].'%')->orWhere('legal_name', 'like', '%'.$filters['customer'].'%'))))
            ->when(filled($filters['ticket'] ?? null), fn ($query) => $query->whereHas('serviceTicket', fn ($ticket) => $ticket->where(fn ($identity) => $identity->where('ticket_number', 'like', '%'.$filters['ticket'].'%')->orWhere('title', 'like', '%'.$filters['ticket'].'%'))))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['date_from'] ?? null), fn ($query) => $query->where(fn ($dates) => $dates->whereDate('issued_at', '>=', $filters['date_from'])->orWhere(fn ($drafts) => $drafts->whereNull('issued_at')->whereDate('created_at', '>=', $filters['date_from']))))
            ->when(filled($filters['date_to'] ?? null), fn ($query) => $query->where(fn ($dates) => $dates->whereDate('issued_at', '<=', $filters['date_to'])->orWhere(fn ($drafts) => $drafts->whereNull('issued_at')->whereDate('created_at', '<=', $filters['date_to']))));

        match ($filters['payment_state'] ?? null) {
            'unpaid' => $query->whereRaw("{$net} BETWEEN 0 AND invoices.total_cents")->whereRaw("{$paid} = 0"),
            'partially_paid' => $query->whereRaw("{$refunded} = 0")->whereRaw("{$paid} > 0")->whereRaw("{$paid} < invoices.total_cents"),
            'paid' => $query->whereRaw("{$refunded} = 0")->whereRaw("{$paid} = invoices.total_cents")->whereRaw("{$paid} > 0"),
            'partially_refunded' => $query->whereRaw("{$refunded} > 0")->whereRaw("{$refunded} < {$paid}")->whereRaw("{$net} <= invoices.total_cents"),
            'refunded' => $query->whereRaw("{$paid} > 0")->whereRaw("{$refunded} = {$paid}"),
            'overpaid' => $query->whereRaw("({$net} < 0 OR {$net} > invoices.total_cents)"),
            default => null,
        };

        match ($filters['balance_state'] ?? null) {
            'open' => $query->whereRaw("{$balance} > 0"),
            'paid' => $query->whereRaw("{$balance} <= 0"),
            'overdue' => $query->where('status', 'issued')->whereNotNull('due_on')->whereDate('due_on', '<', now($organization->timezone)->toDateString())->whereRaw("{$balance} > 0"),
            default => null,
        };

        $direction = $filters['direction'] ?? 'desc';
        match ($filters['sort'] ?? 'date') {
            'invoice' => $query->orderBy('invoice_number', $direction),
            'customer' => $query->orderBy(Customer::query()->select('display_name')->whereColumn('customers.id', 'invoices.customer_id'), $direction),
            'ticket' => $query->orderBy(ServiceTicket::query()->select('ticket_number')->whereColumn('service_tickets.id', 'invoices.service_ticket_id'), $direction),
            'status' => $query->orderBy('status', $direction),
            'due' => $query->orderBy('due_on', $direction),
            'total' => $query->orderBy('total_cents', $direction),
            'balance' => $query->orderByRaw("{$balance} {$direction}"),
            default => $query->orderByRaw("COALESCE(issued_at, created_at) {$direction}"),
        };
        $invoices = $query->orderBy('id', 'desc')->paginate(25)->withQueryString();

        $membership = $request->attributes->get('membership');
        $showReadyHandoffs = $membership->hasCapability('billing_handoffs.view')
            && in_array($filters['workspace'] ?? 'all', ['all', 'ready_to_invoice'], true)
            && blank($filters['invoice'] ?? null)
            && blank($filters['status'] ?? null)
            && blank($filters['payment_state'] ?? null)
            && blank($filters['balance_state'] ?? null);
        $readyHandoffs = collect();
        if ($showReadyHandoffs) {
            $readyHandoffs = BillingHandoff::query()
                ->forOrganization($organization->id)
                ->where('status', 'ready')
                ->whereNull('current_invoice_id')
                ->with(['serviceTicket.customer', 'serviceTicket.serviceLocation', 'closeout'])
                ->when(filled($filters['customer'] ?? null), fn ($query) => $query->whereHas('serviceTicket.customer', fn ($customer) => $customer->where(fn ($names) => $names->where('display_name', 'like', '%'.$filters['customer'].'%')->orWhere('legal_name', 'like', '%'.$filters['customer'].'%'))))
                ->when(filled($filters['ticket'] ?? null), fn ($query) => $query->whereHas('serviceTicket', fn ($ticket) => $ticket->where(fn ($identity) => $identity->where('ticket_number', 'like', '%'.$filters['ticket'].'%')->orWhere('title', 'like', '%'.$filters['ticket'].'%'))))
                ->when(filled($filters['date_from'] ?? null), fn ($query) => $query->whereDate('created_at', '>=', $filters['date_from']))
                ->when(filled($filters['date_to'] ?? null), fn ($query) => $query->whereDate('created_at', '<=', $filters['date_to']))
                ->latest()
                ->limit(25)
                ->get();
        }
        $readyHandoffCount = $membership->hasCapability('billing_handoffs.view')
            ? BillingHandoff::query()->forOrganization($organization->id)->where('status', 'ready')->whereNull('current_invoice_id')->count()
            : 0;

        return view('office.invoices.index', compact('invoices', 'readyHandoffs', 'readyHandoffCount'));
    }

    public function show(
        Request $request,
        string $invoice,
        UnissuedInvoiceDeletionWorkflow $deletion,
        ApprovedVisitLaborWorkflow $visitLabor,
    ): View {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('view', $invoice);
        $invoice->load(['serviceTicket.customer', 'serviceLocation', 'organization', 'lines.laborRate', 'lines.sourceVisit.returnOfVisit', 'lines.catalogSelectedBy', 'closeoutLinks.visit.returnOfVisit', 'closeoutLinks.visit.timeEntries', 'closeoutLinks.closeout.parts', 'closeoutLinks.review.adjustments', 'closeoutLinks.review.tripCharge.selectedBy', 'acknowledgments.presentedBy', 'reissueOf', 'paymentAttempts.configuration', 'paymentTransactions.receipt']);
        if (! $request->attributes->get('membership')->hasCapability('invoices.manage')) {
            return view('office.invoices.summary', compact('invoice'));
        }
        $rates = BillingLaborRate::query()->forOrganization($invoice->organization_id)->where('active', true)->orderByDesc('is_default')->orderBy('name')->get();
        $paymentProviders = PaymentProviderConfiguration::query()->forOrganization($invoice->organization_id)->whereIn('provider', ['square', 'stripe'])->get()->keyBy('provider');
        $defaultPaymentProvider = OrganizationBillingSetting::query()->where('organization_id', $invoice->organization_id)->value('default_payment_provider');
        $readyPaymentProviders = $paymentProviders->filter->isReady();
        $checkoutPaymentProvider = $invoice->electronic_payment_provider ?: $invoice->preferred_payment_provider;
        if (! $checkoutPaymentProvider && $defaultPaymentProvider && $paymentProviders->get($defaultPaymentProvider)?->isReady()) {
            $checkoutPaymentProvider = $defaultPaymentProvider;
        }
        if (! $checkoutPaymentProvider && $readyPaymentProviders->count() === 1) {
            $checkoutPaymentProvider = $readyPaymentProviders->keys()->first();
        }
        $canUseCatalog = $request->attributes->get('membership')->hasCapability('catalog.use');
        $catalogServices = $canUseCatalog ? CatalogService::query()->forOrganization($invoice->organization_id)->where('active', true)->with(['salesUom', 'variants' => fn ($query) => $query->where('active', true)])->orderBy('name')->get() : collect();
        $catalogProducts = $canUseCatalog ? CatalogProduct::query()->forOrganization($invoice->organization_id)->where('active', true)->with('defaultSalesUom')->orderBy('name')->get() : collect();
        $catalogPackages = $canUseCatalog ? CatalogPackage::query()->forOrganization($invoice->organization_id)->where('active', true)->with('salesUom')->orderBy('name')->get() : collect();
        $visitLaborCandidates = $visitLabor->candidates($invoice);

        $canDeleteDraft = Gate::allows('deleteDraft', $invoice) && $deletion->canDelete($invoice);
        $auditEvents = AuditEvent::query()
            ->where('organization_id', $invoice->organization_id)
            ->where(function ($query) use ($invoice): void {
                $query->where(function ($subject) use ($invoice): void {
                    $subject->where('subject_type', Invoice::class)->where('subject_id', $invoice->id);
                });
                if ($invoice->lines->isNotEmpty()) {
                    $query->orWhere(function ($subject) use ($invoice): void {
                        $subject->where('subject_type', InvoiceLine::class)->whereIn('subject_id', $invoice->lines->modelKeys());
                    });
                }
            })
            ->with('actor')
            ->latest('occurred_at')
            ->get();

        return view('office.invoices.show', compact('invoice', 'rates', 'paymentProviders', 'defaultPaymentProvider', 'checkoutPaymentProvider', 'canUseCatalog', 'catalogServices', 'catalogProducts', 'catalogPackages', 'visitLaborCandidates', 'canDeleteDraft', 'auditEvents'));
    }

    public function update(Request $request, string $invoice, InvoiceWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('manage', $invoice);
        $rules = [
            'form_context' => ['nullable', Rule::in(['billing'])],
            'payment_terms' => ['required', Rule::in(['due_on_receipt', 'custom'])], 'due_on' => ['nullable', 'date'],
            'billing_name' => ['required', 'string', 'max:255'], 'billing_legal_name' => ['nullable', 'string', 'max:255'],
            'billing_contact_name' => ['nullable', 'string', 'max:255'], 'billing_email' => ['nullable', 'email', 'max:255'], 'billing_phone' => ['nullable', 'string', 'max:50'],
            'billing_address_line_1' => ['nullable', 'string', 'max:255'], 'billing_address_line_2' => ['nullable', 'string', 'max:255'],
            'billing_city' => ['nullable', 'string', 'max:100'], 'billing_state' => ['nullable', 'string', 'size:2'], 'billing_postal_code' => ['nullable', 'string', 'max:20'],
            'customer_note' => ['nullable', 'string', 'max:5000'], 'internal_note' => ['nullable', 'string', 'max:5000'],
            'tax_rate_percent' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'], 'tax_override_reason' => ['nullable', 'string', 'max:2000'],
        ];
        if ($request->attributes->get('membership')->hasCapability('invoices.discount')) {
            $rules += ['discount_type' => ['nullable', Rule::in(['fixed', 'percent'])], 'discount_value_input' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'], 'discount_reason' => ['nullable', 'string', 'max:2000']];
        }
        $data = $request->validate($rules);
        unset($data['form_context']);
        $data['tax_rate_basis_points'] = $this->decimalToScaled($data['tax_rate_percent'], 100);
        unset($data['tax_rate_percent']);
        if (array_key_exists('discount_type', $data)) {
            $data['discount_value'] = $data['discount_type'] === 'percent'
                ? $this->decimalToScaled((string) ($data['discount_value_input'] ?? '0'), 100)
                : $this->decimalToScaled((string) ($data['discount_value_input'] ?? '0'), 100);
            unset($data['discount_value_input']);
        }
        $workflow->update($invoice, $request->user(), $data);

        return back()->with('status', 'Invoice draft saved.');
    }

    public function storeLine(Request $request, string $invoice, InvoiceWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('manage', $invoice);
        $data = $request->validate($this->lineRules());
        $workflow->addLine($invoice, $request->user(), $this->lineValues($data));

        return back()->with('status', 'Invoice line added.');
    }

    public function storeCatalogLine(Request $request, string $invoice, InvoiceWorkflow $workflow, CatalogLineSnapshotFactory $snapshots): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('manage', $invoice);
        abort_unless($request->attributes->get('membership')->hasCapability('catalog.use'), 403);
        $data = $request->validate([
            'catalog_item' => ['required', 'regex:/^(service|product|package):\d+$/'],
            'catalog_service_variant_id' => ['nullable', 'integer'],
            'catalog_quantity' => ['required', 'regex:/^\d{1,7}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
        ]);
        [$type, $itemId] = explode(':', $data['catalog_item'], 2);
        $quantityMillis = $this->decimalToScaled($data['catalog_quantity'], 1000);
        $snapshot = $snapshots->create($invoice->organization_id, $type, (int) $itemId, $quantityMillis, filled($data['catalog_service_variant_id'] ?? null) ? (int) $data['catalog_service_variant_id'] : null);
        $workflow->addCatalogLine($invoice, $request->user(), $snapshot);

        return back()->with('status', 'Catalog item added with an immutable source snapshot.');
    }

    public function updateLine(Request $request, string $invoice, string $line, InvoiceWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('manage', $invoice);
        $line = $this->invoiceLine($request, $invoice, $line);
        $data = $request->validate($this->lineRules(true));
        $values = $this->lineValues($data);
        if ($line->catalog_item_type && ! empty($values['labor_rate_id'])) {
            return back()->withErrors(['labor_rate_id' => 'Catalog-backed labor cannot use a legacy named labor rate.'])->withInput();
        }
        if (! empty($values['labor_rate_id'])) {
            $rate = BillingLaborRate::query()->forOrganization($invoice->organization_id)->where('active', true)->findOrFail($values['labor_rate_id']);
            $values['unit_price_cents'] = $rate->hourly_rate_cents;
        }
        if ($line->source_part_proposal_id && $values['billing_treatment'] !== $line->billing_treatment && blank($values['override_reason'])) {
            return back()->withErrors(['override_reason' => 'A reason is required to change source billing treatment.'])->withInput();
        }
        if ($line->catalog_item_type && $values['unit_price_cents'] !== $line->unit_price_cents && blank($values['override_reason'])) {
            return back()->withErrors(['override_reason' => 'A reason is required to override Catalog pricing.'])->withInput();
        }
        $workflow->updateLine($invoice, $line, $request->user(), $values);

        return back()->with('status', 'Invoice line updated.');
    }

    public function destroyLine(Request $request, string $invoice, string $line, InvoiceWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('manage', $invoice);
        $line = $this->invoiceLine($request, $invoice, $line);
        $data = $request->validate([
            'line_remove_context' => ['required', Rule::in([(string) $line->id])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $workflow->removeLine($invoice, $line, $request->user(), $data['reason'] ?? null);

        return redirect()->route('office.invoices.show', $invoice)->with('status', 'Invoice line removed and totals recalculated.');
    }

    public function attachVisitLabor(
        Request $request,
        string $invoice,
        string $visit,
        ApprovedVisitLaborWorkflow $workflow,
    ): RedirectResponse {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('manage', $invoice);
        $visit = $this->invoiceVisit($request, $invoice, $visit);
        $workflow->attach($invoice, $visit, $request->user());

        return redirect()->route('office.invoices.show', $invoice)->with('status', $visit->displayNumber().' approved labor added to the invoice.');
    }

    public function includeProposal(Request $request, string $invoice, string $part, InvoiceWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('manage', $invoice);
        $part = VisitPartProposal::query()->where('organization_id', $invoice->organization_id)->findOrFail($part);
        $data = $request->validate(['override_reason' => ['required', 'string', 'max:2000']]);
        $workflow->includeProposal($invoice, $part, $request->user(), $data['override_reason']);

        return back()->with('status', 'Proposal added as a billable invoice line. Enter its price before issue.');
    }

    public function ready(Request $request, string $invoice, InvoiceWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('manage', $invoice);
        $workflow->markReady($invoice, $request->user());

        return back()->with('status', 'Invoice is ready for review.');
    }

    public function issue(Request $request, string $invoice, InvoiceWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('issue', $invoice);
        $data = $request->validate(['issue_token' => ['required', 'uuid'], 'confirm_issue' => ['accepted']]);
        $workflow->issue($invoice, $request->user(), $data['issue_token']);

        return back()->with('status', 'Invoice issued. PDF generation has started.');
    }

    public function void(Request $request, string $invoice, InvoiceWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('void', $invoice);
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:2000'], 'void_token' => ['required', 'uuid'], 'confirm_void' => ['accepted']]);
        $replacement = $workflow->voidAndReissue($invoice, $request->user(), $data['void_reason'], $data['void_token']);

        return redirect()->route('office.invoices.show', $replacement)->with('status', 'Invoice voided and replacement draft created.');
    }

    public function destroy(Request $request, string $invoice, UnissuedInvoiceDeletionWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('deleteDraft', $invoice);
        $data = $request->validate([
            'deletion_reason' => ['required', 'string', 'max:2000'],
            'confirm_invoice_number' => ['required', 'string', Rule::in([$invoice->invoice_number])],
            'confirm_delete' => ['accepted'],
        ]);
        $handoff = $workflow->delete($invoice, $request->user(), $data['deletion_reason']);

        if ($handoff) {
            return redirect()->route('office.billing-handoffs.index')->with('status', 'Unissued invoice deleted. The billing handoff is ready to create a new invoice.');
        }

        return redirect()->route('office.invoices.index')->with('status', 'Unissued direct invoice deleted.');
    }

    public function download(Request $request, string $invoice): StreamedResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('present', $invoice);
        abort_unless($invoice->pdf_status === 'ready' && $invoice->pdf_disk && $invoice->pdf_key, 404);
        abort_unless(Storage::disk($invoice->pdf_disk)->exists($invoice->pdf_key), 404);

        return Storage::disk($invoice->pdf_disk)->download($invoice->pdf_key, $invoice->invoice_number.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function retryPdf(Request $request, string $invoice): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('issue', $invoice);
        abort_unless($invoice->status === 'issued' && $invoice->pdf_status === 'failed', 422);
        $invoice->update(['pdf_status' => 'pending', 'pdf_failure_code' => null]);
        RenderInvoicePdf::dispatch($invoice->id)->afterCommit();

        return back()->with('status', 'PDF generation retry queued.');
    }

    /** @return array<string, mixed> */
    private function lineRules(bool $editing = false): array
    {
        return [
            'line_form_context' => ['nullable', 'string', 'regex:/^(manual|\d+)$/'],
            'line_type' => ['required', Rule::in(['labor', 'travel', 'service_charge', 'part', 'equipment', 'other'])],
            'description' => ['required', 'string', 'max:1000'], 'quantity' => ['required', 'regex:/^\d{1,7}(\.\d{1,3})?$/'],
            'unit' => ['nullable', 'string', 'max:40'], 'unit_price' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'included' => ['nullable', 'boolean'], 'taxable' => ['nullable', 'boolean'],
            'billing_treatment' => ['nullable', Rule::in(['billable', 'warranty', 'customer_owned', 'no_charge'])],
            'labor_rate_id' => ['nullable', 'integer'], 'override_reason' => [$editing ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function lineValues(array $data): array
    {
        return [
            'line_type' => $data['line_type'], 'description' => $data['description'],
            'quantity_millis' => $this->decimalToScaled($data['quantity'], 1000), 'unit' => $data['unit'] ?? null,
            'unit_price_cents' => $this->decimalToScaled($data['unit_price'], 100), 'included' => (bool) ($data['included'] ?? false),
            'taxable' => (bool) ($data['taxable'] ?? false), 'billing_treatment' => $data['billing_treatment'] ?? null,
            'labor_rate_id' => $data['labor_rate_id'] ?? null, 'override_reason' => $data['override_reason'] ?? null,
        ];
    }

    private function decimalToScaled(string $value, int $scale): int
    {
        $digits = strlen((string) $scale) - 1;
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * $scale) + (int) str_pad(substr($decimal, 0, $digits), $digits, '0');
    }

    private function invoice(Request $request, string $id): Invoice
    {
        $organization = $request->attributes->get('organization');
        $invoice = Invoice::query()->forOrganization($organization->id)->find($id);
        if (! $invoice && Invoice::query()->whereKey($id)->exists()) {
            app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, ['record_type' => 'invoice', 'record_id' => (int) $id]);
        }

        return $invoice ?? abort(404);
    }

    private function invoiceLine(Request $request, Invoice $invoice, string $id): InvoiceLine
    {
        $line = InvoiceLine::query()
            ->where('organization_id', $invoice->organization_id)
            ->where('invoice_id', $invoice->id)
            ->find($id);
        if (! $line && InvoiceLine::query()->whereKey($id)->exists()) {
            app(AuditRecorder::class)->record($invoice->organization, $request->user(), 'security.cross_organization_record_denied', $invoice->organization, [
                'record_type' => 'invoice_line',
                'record_id' => (int) $id,
                'invoice_id' => $invoice->id,
            ]);
        }

        return $line ?? abort(404);
    }

    private function invoiceVisit(Request $request, Invoice $invoice, string $id): Visit
    {
        $visit = Visit::query()->forOrganization($invoice->organization_id)->find($id);
        if (! $visit && Visit::query()->withTrashed()->whereKey($id)->exists()) {
            app(AuditRecorder::class)->record($invoice->organization, $request->user(), 'security.cross_organization_record_denied', $invoice->organization, [
                'record_type' => 'visit',
                'record_id' => (int) $id,
                'invoice_id' => $invoice->id,
            ]);
        }

        return $visit ?? abort(404);
    }
}
