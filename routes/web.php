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
use App\Http\Controllers\Office\CatalogCategoryController;
use App\Http\Controllers\Office\CatalogPackageComponentController;
use App\Http\Controllers\Office\CatalogPackageController;
use App\Http\Controllers\Office\CatalogProductController;
use App\Http\Controllers\Office\CatalogProductPurchaseUnitController;
use App\Http\Controllers\Office\CatalogServiceAddonController;
use App\Http\Controllers\Office\CatalogServiceController;
use App\Http\Controllers\Office\CatalogServiceVariantController;
use App\Http\Controllers\Office\CloseoutReviewController;
use App\Http\Controllers\Office\ContactController;
use App\Http\Controllers\Office\CustomerController;
use App\Http\Controllers\Office\DispatchController;
use App\Http\Controllers\Office\InvoiceController;
use App\Http\Controllers\Office\OperationalHealthController;
use App\Http\Controllers\Office\OrganizationSettingsController;
use App\Http\Controllers\Office\PaymentController;
use App\Http\Controllers\Office\PaymentSettingsController;
use App\Http\Controllers\Office\ServiceLocationController;
use App\Http\Controllers\Office\ServiceTicketController;
use App\Http\Controllers\Office\TicketCustomerController;
use App\Http\Controllers\Office\UnitOfMeasureController;
use App\Http\Controllers\Office\VisitArchiveController;
use App\Http\Controllers\Office\VisitController;
use App\Http\Controllers\Office\VisitExecutionController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\PaymentReturnController;
use App\Http\Controllers\PaymentWebhookController;
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

Route::post('/webhooks/payments/{provider}/{configuration}', PaymentWebhookController::class)->whereIn('provider', ['square', 'stripe'])->name('payments.webhook');
Route::get('/payments/return/{attempt}', PaymentReturnController::class)->whereNumber('attempt')->name('payments.return');
Route::get('/receipts/{receipt}/{token}', [PaymentReceiptController::class, 'show'])->whereNumber('receipt')->name('payments.receipts.show');
Route::get('/receipts/{receipt}/{token}/pdf', [PaymentReceiptController::class, 'pdf'])->whereNumber('receipt')->name('payments.receipts.pdf');
Route::get('/receipts/{receipt}/{token}/brand', [PaymentReceiptController::class, 'brand'])->whereNumber('receipt')->name('payments.receipts.brand');

Route::middleware(['auth', 'active.organization', 'record.operational.failures'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/organization-brand/{variant}', [OrganizationSettingsController::class, 'asset'])->whereIn('variant', ['full', 'mark'])->name('organization.brand.asset');

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
                Route::get('/service-tickets/customer-options', [TicketCustomerController::class, 'index'])->name('service-tickets.customer-options');
                Route::post('/service-tickets/quick-customers', [TicketCustomerController::class, 'store'])->name('service-tickets.quick-customers.store');
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
                Route::post('/invoices/{invoice}/catalog-lines', [InvoiceController::class, 'storeCatalogLine'])->whereNumber('invoice')->middleware('capability:catalog.use')->name('invoices.catalog-lines.store');
                Route::put('/invoices/{invoice}/lines/{line}', [InvoiceController::class, 'updateLine'])->whereNumber(['invoice', 'line'])->name('invoices.lines.update');
                Route::post('/invoices/{invoice}/proposals/{part}', [InvoiceController::class, 'includeProposal'])->whereNumber(['invoice', 'part'])->name('invoices.proposals.include');
                Route::post('/invoices/{invoice}/ready', [InvoiceController::class, 'ready'])->whereNumber('invoice')->name('invoices.ready');
            });
            Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->whereNumber('invoice')->middleware('capability:invoices.issue')->name('invoices.issue');
            Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->whereNumber('invoice')->middleware('capability:invoices.void')->name('invoices.void');
            Route::post('/invoices/{invoice}/pdf/retry', [InvoiceController::class, 'retryPdf'])->whereNumber('invoice')->middleware('capability:invoices.issue')->name('invoices.pdf.retry');
            Route::post('/invoices/{invoice}/payments/checkout', [PaymentController::class, 'checkout'])->whereNumber('invoice')->name('invoices.payments.checkout');
            Route::put('/invoices/{invoice}/payments/provider', [PaymentController::class, 'provider'])->whereNumber('invoice')->name('invoices.payments.provider');
            Route::post('/invoices/{invoice}/payments/manual', [PaymentController::class, 'manual'])->whereNumber('invoice')->name('invoices.payments.manual');
            Route::post('/invoices/{invoice}/payments/{attempt}/expire', [PaymentController::class, 'expire'])->whereNumber(['invoice', 'attempt'])->name('invoices.payments.expire');
            Route::post('/invoices/{invoice}/payments/{attempt}/reconcile', [PaymentController::class, 'reconcile'])->whereNumber(['invoice', 'attempt'])->name('invoices.payments.reconcile');
            Route::get('/invoices/{invoice}/payments/{attempt}/qr', [PaymentController::class, 'qr'])->whereNumber(['invoice', 'attempt'])->name('invoices.payments.qr');
            Route::post('/invoices/{invoice}/transactions/{transaction}/refund', [PaymentController::class, 'refund'])->whereNumber(['invoice', 'transaction'])->name('invoices.transactions.refund');
            Route::post('/invoices/{invoice}/receipts/{receipt}/link', [PaymentController::class, 'receiptLink'])->whereNumber(['invoice', 'receipt'])->name('invoices.receipts.link');
            Route::post('/invoices/{invoice}/receipts/{receipt}/retry', [PaymentController::class, 'retryReceipt'])->whereNumber(['invoice', 'receipt'])->name('invoices.receipts.retry');
            Route::get('/settings', [OrganizationSettingsController::class, 'index'])->name('settings.index');
            Route::get('/settings/billing', [BillingSettingsController::class, 'edit'])->name('settings.billing.edit');
            Route::put('/settings/billing/payments/{provider}', [PaymentSettingsController::class, 'update'])->whereIn('provider', ['square', 'stripe'])->name('settings.billing.payments.update');
            Route::post('/settings/billing/payments/{provider}/test', [PaymentSettingsController::class, 'test'])->whereIn('provider', ['square', 'stripe'])->name('settings.billing.payments.test');
            Route::post('/settings/billing/payments/{provider}/toggle', [PaymentSettingsController::class, 'toggle'])->whereIn('provider', ['square', 'stripe'])->name('settings.billing.payments.toggle');
            Route::delete('/settings/billing/payments/{provider}', [PaymentSettingsController::class, 'clear'])->whereIn('provider', ['square', 'stripe'])->name('settings.billing.payments.clear');
            Route::middleware('capability:organization.settings.manage')->group(function (): void {
                Route::get('/settings/organization', [OrganizationSettingsController::class, 'edit'])->name('settings.organization.edit');
                Route::put('/settings/organization', [OrganizationSettingsController::class, 'update'])->name('settings.organization.update');
                Route::post('/settings/organization/brand/{variant}', [OrganizationSettingsController::class, 'upload'])->whereIn('variant', ['full', 'mark'])->name('settings.organization.brand.upload');
                Route::delete('/settings/organization/brand/{variant}', [OrganizationSettingsController::class, 'remove'])->whereIn('variant', ['full', 'mark'])->name('settings.organization.brand.remove');
            });
            Route::middleware('capability:billing.settings.manage')->group(function (): void {
                Route::put('/settings/billing', [BillingSettingsController::class, 'update'])->name('settings.billing.update');
                Route::post('/settings/billing/labor-rates', [BillingSettingsController::class, 'storeRate'])->name('settings.billing.rates.store');
                Route::put('/settings/billing/labor-rates/{rate}', [BillingSettingsController::class, 'updateRate'])->whereNumber('rate')->name('settings.billing.rates.update');
                Route::get('/settings/invoices', [BillingSettingsController::class, 'invoiceEdit'])->name('settings.invoices.edit');
                Route::put('/settings/invoices', [BillingSettingsController::class, 'invoiceUpdate'])->name('settings.invoices.update');
                Route::redirect('/billing/settings', '/office/settings/billing')->name('billing.settings.edit');
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
            Route::prefix('catalog')->name('catalog.')->middleware('capability:catalog.view')->group(function (): void {
                Route::get('/', fn () => redirect()->route('office.catalog.services.index'))->name('index');
                Route::get('/services', [CatalogServiceController::class, 'index'])->name('services.index');
                Route::get('/services/{service}', [CatalogServiceController::class, 'show'])->whereNumber('service')->name('services.show');
                Route::get('/products', [CatalogProductController::class, 'index'])->name('products.index');
                Route::get('/products/{product}', [CatalogProductController::class, 'show'])->whereNumber('product')->name('products.show');
                Route::get('/packages', [CatalogPackageController::class, 'index'])->name('packages.index');
                Route::get('/packages/{package}', [CatalogPackageController::class, 'show'])->whereNumber('package')->name('packages.show');
                Route::get('/categories', [CatalogCategoryController::class, 'index'])->name('categories.index');
                Route::get('/units', [UnitOfMeasureController::class, 'index'])->name('units.index');
                Route::middleware('capability:catalog.manage')->group(function (): void {
                    Route::get('/services/create', [CatalogServiceController::class, 'create'])->name('services.create');
                    Route::post('/services', [CatalogServiceController::class, 'store'])->name('services.store');
                    Route::get('/services/{service}/edit', [CatalogServiceController::class, 'edit'])->whereNumber('service')->name('services.edit');
                    Route::put('/services/{service}', [CatalogServiceController::class, 'update'])->whereNumber('service')->name('services.update');
                    Route::post('/services/{service}/variants', [CatalogServiceVariantController::class, 'store'])->whereNumber('service')->name('services.variants.store');
                    Route::put('/services/{service}/variants/{variant}', [CatalogServiceVariantController::class, 'update'])->whereNumber(['service', 'variant'])->name('services.variants.update');
                    Route::put('/services/{service}/addons', [CatalogServiceAddonController::class, 'update'])->whereNumber('service')->name('services.addons.update');
                    Route::get('/products/create', [CatalogProductController::class, 'create'])->name('products.create');
                    Route::post('/products', [CatalogProductController::class, 'store'])->name('products.store');
                    Route::get('/products/{product}/edit', [CatalogProductController::class, 'edit'])->whereNumber('product')->name('products.edit');
                    Route::put('/products/{product}', [CatalogProductController::class, 'update'])->whereNumber('product')->name('products.update');
                    Route::post('/products/{product}/purchase-units', [CatalogProductPurchaseUnitController::class, 'store'])->whereNumber('product')->name('products.purchase-units.store');
                    Route::put('/products/{product}/purchase-units/{purchaseUnit}', [CatalogProductPurchaseUnitController::class, 'update'])->whereNumber(['product', 'purchaseUnit'])->name('products.purchase-units.update');
                    Route::get('/packages/create', [CatalogPackageController::class, 'create'])->name('packages.create');
                    Route::post('/packages', [CatalogPackageController::class, 'store'])->name('packages.store');
                    Route::get('/packages/{package}/edit', [CatalogPackageController::class, 'edit'])->whereNumber('package')->name('packages.edit');
                    Route::put('/packages/{package}', [CatalogPackageController::class, 'update'])->whereNumber('package')->name('packages.update');
                    Route::post('/packages/{package}/components', [CatalogPackageComponentController::class, 'store'])->whereNumber('package')->name('packages.components.store');
                    Route::put('/packages/{package}/components/{component}', [CatalogPackageComponentController::class, 'update'])->whereNumber(['package', 'component'])->name('packages.components.update');
                    Route::get('/categories/create', [CatalogCategoryController::class, 'create'])->name('categories.create');
                    Route::post('/categories', [CatalogCategoryController::class, 'store'])->name('categories.store');
                    Route::get('/categories/{category}/edit', [CatalogCategoryController::class, 'edit'])->whereNumber('category')->name('categories.edit');
                    Route::put('/categories/{category}', [CatalogCategoryController::class, 'update'])->whereNumber('category')->name('categories.update');
                    Route::get('/units/create', [UnitOfMeasureController::class, 'create'])->name('units.create');
                    Route::post('/units', [UnitOfMeasureController::class, 'store'])->name('units.store');
                    Route::get('/units/{unit}/edit', [UnitOfMeasureController::class, 'edit'])->whereNumber('unit')->name('units.edit');
                    Route::put('/units/{unit}', [UnitOfMeasureController::class, 'update'])->whereNumber('unit')->name('units.update');
                });
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
            Route::post('/visits/{visit}/catalog-items', [ExecutionController::class, 'addCatalogItem'])->middleware('capability:catalog.use')->name('visits.catalog-items.store');
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
    Route::get('/invoices/{invoice}/brand', [InvoicePresentationController::class, 'brand'])->whereNumber('invoice')->middleware('capability:invoices.present')->name('invoices.brand');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'download'])->whereNumber('invoice')->middleware('capability:invoices.present')->name('invoices.pdf');
    Route::post('/invoices/{invoice}/acknowledge', [InvoicePresentationController::class, 'acknowledge'])->whereNumber('invoice')->middleware('capability:invoices.present')->name('invoices.acknowledge');
    Route::post('/invoices/{invoice}/payments/checkout', [PaymentController::class, 'checkout'])->whereNumber('invoice')->name('invoices.payments.checkout');
    Route::put('/invoices/{invoice}/payments/provider', [PaymentController::class, 'provider'])->whereNumber('invoice')->name('invoices.payments.provider');
    Route::post('/invoices/{invoice}/payments/{attempt}/expire', [PaymentController::class, 'expire'])->whereNumber(['invoice', 'attempt'])->name('invoices.payments.expire');
    Route::post('/invoices/{invoice}/payments/{attempt}/reconcile', [PaymentController::class, 'reconcile'])->whereNumber(['invoice', 'attempt'])->name('invoices.payments.reconcile');
    Route::get('/invoices/{invoice}/payments/{attempt}/qr', [PaymentController::class, 'qr'])->whereNumber(['invoice', 'attempt'])->name('invoices.payments.qr');
});
