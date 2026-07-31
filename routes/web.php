<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Field\CustomerDirectoryController as FieldCustomerDirectoryController;
use App\Http\Controllers\Office\ContactController;
use App\Http\Controllers\Office\CustomerController;
use App\Http\Controllers\Office\ServiceLocationController;
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

Route::middleware(['auth', 'active.organization'])->group(function (): void {
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
            Route::view('/', 'field.home')->name('home');
            Route::middleware('capability:customers.view')->group(function (): void {
                Route::get('/customers', [FieldCustomerDirectoryController::class, 'index'])->name('customers.index');
                Route::get('/customers/{customer}', [FieldCustomerDirectoryController::class, 'showCustomer'])->whereNumber('customer')->name('customers.show');
                Route::get('/locations/{location}', [FieldCustomerDirectoryController::class, 'showLocation'])->whereNumber('location')->name('locations.show');
            });
        });
});
