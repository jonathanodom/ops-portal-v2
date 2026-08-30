<?php

use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| JARVIS-facing API — /api/v1
|--------------------------------------------------------------------------
|
| Authoritative contract: docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md
| Every route in this file is versioned. Do not add unversioned routes here.
|
| Every scoped route carries two independent authorization checks:
|   - abilities:<scope>   Sanctum token ability (per-token, revocable/rotatable)
|   - capability:api.*    Organization membership capability (per-identity grant)
| Both must pass. See .cursor/rules/ops-portal-api-development.mdc §10 and
| docs/OP_API_0_REPOSITORY_ASSESSMENT.md §8.3.
|
*/

Route::prefix('v1')->middleware(['api.request.size', 'auth:sanctum', 'active.organization', 'throttle:jarvis-api'])->group(function (): void {
    Route::get('/me', MeController::class)->name('api.v1.me');

    Route::middleware(['abilities:customers.read', 'capability:api.customers.read'])->group(function (): void {
        Route::get('/customers/search', [CustomerController::class, 'search'])->name('api.v1.customers.search');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('api.v1.customers.show');
    });

    Route::get('/customers/{customer}/locations', [CustomerController::class, 'locations'])
        ->middleware(['abilities:locations.read', 'capability:api.locations.read'])
        ->name('api.v1.customers.locations');

    Route::get('/contacts/search', [ContactController::class, 'search'])
        ->middleware(['abilities:contacts.read', 'capability:api.contacts.read'])
        ->name('api.v1.contacts.search');

    Route::middleware(['abilities:tickets.read', 'capability:api.tickets.read'])->group(function (): void {
        Route::get('/tickets', [TicketController::class, 'index'])->name('api.v1.tickets.index');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('api.v1.tickets.show');
    });

    Route::post('/tickets', [TicketController::class, 'store'])
        ->middleware(['abilities:tickets.create', 'capability:api.tickets.create'])
        ->name('api.v1.tickets.store');

    Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])
        ->middleware(['abilities:tickets.update', 'capability:api.tickets.update'])
        ->name('api.v1.tickets.update');

    Route::middleware(['abilities:projects.read', 'capability:api.projects.read'])->group(function (): void {
        Route::get('/customers/{customer}/projects', [ProjectController::class, 'forCustomer'])->name('api.v1.customers.projects');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('api.v1.projects.show');
    });
});
