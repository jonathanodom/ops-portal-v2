<?php

use App\Http\Middleware\CorrelateRequest;
use App\Http\Middleware\RecordOperationalFailures;
use App\Http\Middleware\RequireCapability;
use App\Http\Middleware\ResolveActiveOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CorrelateRequest::class);
        $middleware->alias([
            'active.organization' => ResolveActiveOrganization::class,
            'capability' => RequireCapability::class,
            'record.operational.failures' => RecordOperationalFailures::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
