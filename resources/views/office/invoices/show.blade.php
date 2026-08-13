<x-layouts.office :title="$invoice->invoice_number" width="workspace">
    <x-office.invoice-command-bar :invoice="$invoice" :can-delete-draft="$canDeleteDraft ?? false" />

    @if(session('status'))<div class="mt-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mt-5 rounded-lg border border-red-300 bg-red-50 p-4 text-red-900" role="alert"><p class="font-bold">Invoice needs attention</p><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($invoice->status === 'void')<div class="mt-5 rounded-lg border border-red-300 bg-red-50 p-4 font-semibold text-red-900">This invoice is void and immutable. Open the current invoice from Billing.</div>@endif

    @include('office.invoices._billing-summary')

    <div class="invoice-workspace" data-invoice-workspace>
        @include('office.invoices._line-workspace')
        @include('office.invoices._lower-section')

        @if(($activeMembership->hasCapability('invoices.void') && $invoice->status !== 'void') || ($canDeleteDraft ?? false))
            <div class="grid gap-6 xl:grid-cols-2">
                @if($activeMembership->hasCapability('invoices.void') && $invoice->status !== 'void')
                    <form id="void-reissue" method="POST" action="{{ route('office.invoices.void', $invoice) }}" class="surface scroll-mt-32 border-red-200 p-5">@csrf
                        <input type="hidden" name="void_token" value="{{ Str::uuid() }}">
                        <h2 class="font-bold text-red-800">Void and reissue</h2>
                        <label class="form-label mt-3" for="void_reason">Reason</label><textarea class="form-textarea" id="void_reason" name="void_reason" required></textarea>
                        <label class="mt-3 flex min-h-11 items-center gap-2"><input type="checkbox" name="confirm_void" value="1" required> Create a newly numbered replacement</label>
                        <button class="button-danger mt-3 w-full">Void and reissue</button>
                    </form>
                @endif
                @if($canDeleteDraft ?? false)
                    <form id="delete-unissued-invoice" method="POST" action="{{ route('office.invoices.destroy', $invoice) }}" class="surface scroll-mt-32 border-red-300 bg-red-50 p-5">@csrf @method('DELETE')
                        <h2 class="font-bold text-red-900">Delete unissued invoice</h2>
                        <p class="mt-2 text-sm text-red-800">This removes the draft and returns the Billing Handoff to Ready. Issued invoices must use Void and reissue.</p>
                        <label class="form-label mt-3" for="deletion_reason">Deletion reason</label><textarea class="form-textarea" id="deletion_reason" name="deletion_reason" required maxlength="2000"></textarea>
                        <label class="form-label mt-3" for="confirm_invoice_number">Enter {{ $invoice->invoice_number }} to confirm</label><input class="form-input" id="confirm_invoice_number" name="confirm_invoice_number" required autocomplete="off">
                        <label class="mt-3 flex min-h-11 items-center gap-2"><input type="checkbox" name="confirm_delete" value="1" required> I understand this invoice will be removed.</label>
                        <button class="button-danger mt-3 w-full">Delete unissued invoice</button>
                    </form>
                @endif
            </div>
        @endif

        @include('office.invoices._payments')
    </div>
</x-layouts.office>
