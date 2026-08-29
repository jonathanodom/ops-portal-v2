<?php

use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| JARVIS-facing API — /api/v1
|--------------------------------------------------------------------------
|
| Authoritative contract: docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md
| Every route in this file is versioned. Do not add unversioned routes here.
|
*/

Route::prefix('v1')->middleware(['auth:sanctum', 'active.organization'])->group(function (): void {
    Route::get('/me', MeController::class)->name('api.v1.me');
});
