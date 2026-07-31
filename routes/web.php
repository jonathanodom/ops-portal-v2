<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
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
        });

    Route::prefix('field')
        ->name('field.')
        ->middleware('capability:experience.field.access')
        ->group(function (): void {
            Route::view('/', 'field.home')->name('home');
        });
});
