<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Field\CustomerDirectoryController as FieldCustomerDirectoryController;
use App\Http\Controllers\Field\ExecutionController;
use App\Http\Controllers\Field\TodayController;
use App\Http\Controllers\InvoicePresentationController;
use App\Http\Controllers\Office\AdminManualCloseoutController;
use App\Http\Controllers\Office\BillingHandoffController;
use App\Http\Controllers\Office\BillingSettingsController;
use App\Http\Controllers\Office\CloseoutReviewController;
use App\Http\Controllers\Office\ContactController;
use App\Http\Controllers\Office\CustomerController;
use App\Http\Controllers\Office\DispatchController;
use App\Http\Controllers\Office\InvoiceController;
use App\Http\Controllers\Office\OperationalHealthController;
use App\Http\Controllers\Office\ServiceLocationController;
use App\Http\Controllers\Office\ServiceTicketController;
use App\Http\Controllers\Office\VisitArchiveController;
use App\Http\Controllers\Office\VisitController;
use App\Http\Controllers\Office\VisitExecutionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::middleware(['auth', 'active.organization', 'record.operational.failures'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', function (Request $request) {
        $membership = $request->attributes->get('membership');

        if ($membership->hasCapability('experience.office.access')) {
            return redirect()->route('office.home');
        }

        if ($membership->hasCapability('experience.field.access')) {
            return redirect()->route('field.home');
        }

        abort(403);
    })->name('home');

    Route::prefix('office')
        ->name('office.')
        ->middleware('capability:experience.office.access')
        ->group(function (): void {
            Route::view('/', 'office.home')->name('home');
            Route::middleware('capability:service_tickets.view')->group(function (): void {
                Route::get('/service-tickets', [ServiceTicketController::class, 'index'])->name('service-tickets.index');
                Route::get('/service-tickets/{serviceTicket}', [ServiceTicketController::class, 'show'])->whereNumber('serviceTicket')->name('service-tickets.show');
                Route::get('/dispatch', [DispatchController::class, 'index'])->name('dispatch.index');
            });
            Route::middleware('capability:dispatch.manage')->group(function (): void {
                Route::get('/service-tickets/create', [ServiceTicketController::class, 'create'])->name('service-tickets.create');
                Route::post('/service-tickets', [ServiceTicketController::class, 'store'])->name('service-tickets.store');
                Route::get('/service-tickets/{serviceTicket}/edit', [ServiceTicketController::class, 'edit'])->whereNumber('serviceTicket')->name('service-tickets.edit');
                Route::put('/service-tickets/{serviceTicket}', [ServiceTicketController::class, 'update'])->whereNumber('serviceTicket')->name('service-tickets.update');
                Route::post('/service-tickets/{serviceTicket}/notes', [ServiceTicketController::class, 'addNote'])->whereNumber('serviceTicket')->name('service-tickets.notes.store');
                Route::post('/service-tickets/{serviceTicket}/transition', [ServiceTicketController::class, 'transition'])->whereNumber('serviceTicket')->name('service-tickets.transition');
                Route::get('/service-tickets/{serviceTicket}/visits/create', [VisitController::class, 'create'])->whereNumber('serviceTicket')->name('service-tickets.visits.create');
                Route::post('/service-tickets/{serviceTicket}/visits', [VisitController::class, 'store'])->whereNumber('serviceTicket')->name('service-tickets.visits.store');
                Route::get('/visits/{visit}/edit', [VisitController::class, 'edit'])->whereNumber('visit')->name('visits.edit');
                Route::put('/visits/{visit}', [VisitController::class, 'update'])->whereNumber('visit')->name('visits.update');
                Route::post('/visits/{visit}/cancel', [VisitController::class, 'cancel'])->whereNumber('visit')->name('visits.cancel');
                Route::post('/visits/{visit}/return', [VisitController::class, 'createReturn'])->whereNumber('visit')->name('visits.return');
            });
            Route::middleware('capability:closeouts.inspect')->group(function (): void {
                Route::get('/closeout-reviews', [CloseoutReviewController::class, 'index'])->name('closeout-reviews.index');
                Route::get('/closeout-reviews/{closeout}', [CloseoutReviewController::class, 'show'])->whereNumber('closeout')->name('closeout-reviews.show');
            });
            Route::middleware('capability:closeouts.review')->group(function (): void {
                Route::post('/closeout-reviews/{closeout}/approve', [CloseoutReviewController::class, 'approve'])->whereNumber('closeout')->name('closeout-reviews.approve');
                Route::post('/closeout-reviews/{closeout}/return', [CloseoutReviewController::class, 'returnForCorrection'])->whereNumber('closeout')->name('closeout-reviews.return');
            });
            Route::middleware('capability:closeouts.manual_complete')->group(function (): void {
                Route::post('/visits/{visit}/manual-closeout/start', [AdminManualCloseoutController::class, 'start'])->whereNumber('visit')->name('visits.manual-closeout.start');
                Route::post('/visits/{visit}/manual-closeout/draft', [AdminManualCloseoutController::class, 'save'])->whereNumber('visit')->name('visits.manual-closeout.save');
                Route::post('/visits/{visit}/manual-closeout/complete', [AdminManualCloseoutController::class, 'complete'])->whereNumber('visit')->name('visits.manual-closeout.complete');
                Route::post('/visits/{visit}/manual-closeout/parts', [AdminManualCloseoutController::class, 'addPart'])->whereNumber('visit')->name('visits.manual-closeout.parts.store');
                Route::delete('/visits/{visit}/manual-closeout/parts/{part}', [AdminManualCloseoutController::class, 'removePart'])->whereNumber(['visit', 'part'])->name('visits.manual-closeout.parts.remove');
                Route::post('/visits/{visit}/manual-closeout/media', [AdminManualCloseoutController::class, 'upload'])->whereNumber('visit')->name('visits.manual-closeout.media.store');
                Route::delete('/visits/{visit}/manual-closeout/media/{media}', [AdminManualCloseoutController::class, 'removeMedia'])->whereNumber(['visit', 'media'])->name('visits.manual-closeout.media.remove');
                Route::get('/manual-closeout-media/{media}', [AdminManualCloseoutController::class, 'media'])->whereNumber('media')->name('manual-closeout.media.show');
            });
            Route::middleware('capability:billing_handoffs.view')->group(function (): void {
                Route::get('/billing-handoffs', [BillingHandoffController::class, 'index'])->name('billing-handoffs.index');
            });
            Route::post('/billing-handoffs/{handoff}/invoice', [BillingHandoffController::class, 'createInvoice'])
                ->whereNumber('handoff')->middleware('capability:invoices.manage')->name('billing-handoffs.invoice.store');
            Route::middleware('capability:invoices.view')->group(function (): void {
                Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
            });
            Route::middleware('capability:invoices.manage')->group(function (): void {
                Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->whereNumber('invoice')->name('invoices.update');
                Route::post('/invoices/{invoice}/lines', [InvoiceController::class, 'storeLine'])->whereNumber('invoice')->name('invoices.lines.store');
                Route::put('/invoices/{invoice}/lines/{line}', [InvoiceController::class, 'updateLine'])->whereNumber(['invoice', 'line'])->name('invoices.lines.update');
                Route::post('/invoices/{invoice}/proposals/{part}', [InvoiceController::class, 'includeProposal'])->whereNumber(['invoice', 'part'])->name('invoices.proposals.include');
                Route::post('/invoices/{invoice}/ready', [InvoiceController::class, 'ready'])->whereNumber('invoice')->name('invoices.ready');
            });
            Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->whereNumber('invoice')->middleware('capability:invoices.issue')->name('invoices.issue');
            Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->whereNumber('invoice')->middleware('capability:invoices.void')->name('invoices.void');
            Route::post('/invoices/{invoice}/pdf/retry', [InvoiceController::class, 'retryPdf'])->whereNumber('invoice')->middleware('capability:invoices.issue')->name('invoices.pdf.retry');
            Route::middleware('capability:billing.settings.manage')->group(function (): void {
                Route::get('/billing/settings', [BillingSettingsController::class, 'edit'])->name('billing.settings.edit');
                Route::put('/billing/settings', [BillingSettingsController::class, 'update'])->name('billing.settings.update');
                Route::post('/billing/settings/labor-rates', [BillingSettingsController::class, 'storeRate'])->name('billing.settings.rates.store');
                Route::put('/billing/settings/labor-rates/{rate}', [BillingSettingsController::class, 'updateRate'])->whereNumber('rate')->name('billing.settings.rates.update');
            });
            Route::post('/visits/{visit}/execution/transition', [VisitExecutionController::class, 'transition'])->whereNumber('visit')->name('visits.execution.transition');
            Route::post('/visits/{visit}/execution/timer', [VisitExecutionController::class, 'timer'])->whereNumber('visit')->name('visits.execution.timer');
            Route::post('/visits/{visit}/execution/time', [VisitExecutionController::class, 'storeTime'])->whereNumber('visit')->name('visits.execution.time.store');
            Route::put('/visits/{visit}/execution/time/{entry}', [VisitExecutionController::class, 'updateTime'])->whereNumber(['visit', 'entry'])->name('visits.execution.time.update');
            Route::get('/operations/health', [OperationalHealthController::class, 'index'])
                ->middleware('capability:operations.health.view')->name('operations.health');
            Route::post('/operations/incidents/{incident}/resolve', [OperationalHealthController::class, 'resolve'])
                ->whereNumber('incident')->middleware('capability:operations.health.manage')->name('operations.resolve');
            Route::post('/operations/incidents/{incident}/reopen', [OperationalHealthController::class, 'reopen'])
                ->whereNumber('incident')->middleware('capability:operations.health.manage')->name('operations.reopen');
            Route::middleware('capability:visits.archive.manage')->group(function (): void {
                Route::get('/admin/archive', [VisitArchiveController::class, 'index'])->name('admin.archive.index');
                Route::post('/admin/archive/visits/{visit}', [VisitArchiveController::class, 'archive'])->whereNumber('visit')->name('admin.archive.visits.store');
                Route::post('/admin/archive/visits/{visit}/restore', [VisitArchiveController::class, 'restore'])->whereNumber('visit')->name('admin.archive.visits.restore');
                Route::delete('/admin/archive/visits/{visit}', [VisitArchiveController::class, 'destroy'])->whereNumber('visit')->name('admin.archive.visits.destroy');
            });
            Route::middleware('capability:customers.view')->group(function (): void {
                Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
                Route::get('/customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
                Route::get('/locations', [ServiceLocationController::class, 'index'])->name('locations.index');
                Route::get('/locations/{location}', [ServiceLocationController::class, 'show'])->whereNumber('location')->name('locations.show');
            });
            Route::middleware('capability:customers.manage')->group(function (): void {
                Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
                Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
                Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->whereNumber('customer')->name('customers.edit');
                Route::put('/customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
                Route::get('/customers/{customer}/contacts/create', [ContactController::class, 'create'])->whereNumber('customer')->name('customers.contacts.create');
                Route::post('/customers/{customer}/contacts', [ContactController::class, 'store'])->whereNumber('customer')->name('customers.contacts.store');
                Route::get('/customers/{customer}/contacts/{contact}/edit', [ContactController::class, 'edit'])->whereNumber(['customer', 'contact'])->name('customers.contacts.edit');
                Route::put('/customers/{customer}/contacts/{contact}', [ContactController::class, 'update'])->whereNumber(['customer', 'contact'])->name('customers.contacts.update');
                Route::get('/customers/{customer}/locations/create', [ServiceLocationController::class, 'create'])->whereNumber('customer')->name('customers.locations.create');
                Route::post('/customers/{customer}/locations', [ServiceLocationController::class, 'store'])->whereNumber('customer')->name('customers.locations.store');
                Route::get('/locations/{location}/edit', [ServiceLocationController::class, 'edit'])->whereNumber('location')->name('locations.edit');
                Route::put('/locations/{location}', [ServiceLocationController::class, 'update'])->whereNumber('location')->name('locations.update');
            });
        });

    Route::prefix('field')
        ->name('field.')
        ->middleware('capability:experience.field.access')
        ->group(function (): void {
            Route::get('/', [TodayController::class, 'index'])->name('home');
            Route::get('/visits/{visit}', [TodayController::class, 'show'])->whereNumber('visit')->name('visits.show');
            Route::post('/visits/{visit}/transition', [TodayController::class, 'transition'])->whereNumber('visit')->name('visits.transition');
            Route::post('/visits/{visit}/draft', [ExecutionController::class, 'save'])->name('visits.draft');
            Route::post('/visits/{visit}/timer', [ExecutionController::class, 'timer'])->name('visits.timer');
            Route::put('/visits/{visit}/time/{entry}', [ExecutionController::class, 'updateTime'])->name('visits.time.update');
            Route::post('/visits/{visit}/parts', [ExecutionController::class, 'addPart'])->name('visits.parts.store');
            Route::delete('/visits/{visit}/parts/{part}', [ExecutionController::class, 'removePart'])->name('visits.parts.remove');
            Route::post('/visits/{visit}/media', [ExecutionController::class, 'upload'])->name('visits.media.store');
            Route::delete('/visits/{visit}/media/{media}', [ExecutionController::class, 'removeMedia'])->name('visits.media.remove');
            Route::post('/visits/{visit}/submit', [ExecutionController::class, 'submit'])->name('visits.submit');
            Route::middleware('capability:customers.view')->group(function (): void {
                Route::get('/customers', [FieldCustomerDirectoryController::class, 'index'])->name('customers.index');
                Route::get('/customers/{customer}', [FieldCustomerDirectoryController::class, 'showCustomer'])->whereNumber('customer')->name('customers.show');
                Route::get('/locations/{location}', [FieldCustomerDirectoryController::class, 'showLocation'])->whereNumber('location')->name('locations.show');
            });
        });
    Route::get('/field-media/{media}', [ExecutionController::class, 'media'])->whereNumber('media')->name('field.media.show');
    Route::get('/invoices/{invoice}/present', [InvoicePresentationController::class, 'show'])->whereNumber('invoice')->middleware('capability:invoices.present')->name('invoices.present');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'download'])->whereNumber('invoice')->middleware('capability:invoices.present')->name('invoices.pdf');
    Route::post('/invoices/{invoice}/acknowledge', [InvoicePresentationController::class, 'acknowledge'])->whereNumber('invoice')->middleware('capability:invoices.present')->name('invoices.acknowledge');
});
