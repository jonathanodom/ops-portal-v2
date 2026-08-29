<?php

use App\Http\Middleware\CorrelateRequest;
use App\Http\Middleware\RecordOperationalFailures;
use App\Http\Middleware\RequireCapability;
use App\Http\Middleware\ResolveActiveOrganization;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CorrelateRequest::class);
        $middleware->validateCsrfTokens(except: ['webhooks/payments/*']);
        $middleware->alias([
            'active.organization' => ResolveActiveOrganization::class,
            'capability' => RequireCapability::class,
            'record.operational.failures' => RecordOperationalFailures::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return ApiResponse::error($request, 'validation_failed', 'The given data was invalid.', 422, $e->errors());
            }

            if ($e instanceof AuthenticationException) {
                return ApiResponse::error($request, 'unauthenticated', 'Authentication is required.', 401);
            }

            if ($e instanceof AuthorizationException) {
                return ApiResponse::error($request, 'forbidden', $e->getMessage() ?: 'This action is unauthorized.', 403);
            }

            if ($e instanceof ModelNotFoundException) {
                return ApiResponse::error($request, 'not_found', 'The requested resource was not found.', 404);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    401 => 'unauthenticated',
                    403 => 'forbidden',
                    404 => 'not_found',
                    405 => 'method_not_allowed',
                    409 => 'conflict',
                    422 => 'unprocessable',
                    429 => 'rate_limited',
                    default => $status >= 500 ? 'server_error' : 'request_failed',
                };
                $message = $status >= 500 ? 'An unexpected error occurred.' : ($e->getMessage() ?: 'The request could not be completed.');

                return ApiResponse::error($request, $code, $message, $status);
            }

            return ApiResponse::error($request, 'server_error', 'An unexpected error occurred.', 500);
        });
    })->create();
