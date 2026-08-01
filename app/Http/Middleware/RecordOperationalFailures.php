<?php

namespace App\Http\Middleware;

use App\Support\IncidentRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordOperationalFailures
{
    private const TRANSITION_ROUTES = [
        'field.visits.transition', 'field.visits.submit', 'office.service-tickets.transition',
        'office.visits.cancel', 'office.visits.return', 'office.closeout-reviews.approve',
        'office.closeout-reviews.return',
    ];

    public function __construct(private IncidentRecorder $incidents) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (ValidationException $exception) {
            if (in_array($request->route()?->getName(), self::TRANSITION_ROUTES, true)) {
                $this->incidents->record(
                    $request->attributes->get('organization'),
                    $request->user(),
                    'transition_rejected',
                    'warning',
                    null,
                    $this->safeRequestContext($request, ['invalid_fields' => array_keys($exception->errors())]),
                    $request->attributes->get('request_id'),
                );
            }

            throw $exception;
        } catch (Throwable $exception) {
            $route = $request->route()?->getName();
            $category = $route === 'field.visits.media.store' ? 'upload_failure' : 'request_failure';
            if ($route === 'field.media.show') {
                $category = 'storage_failure';
            }
            if (in_array($route, self::TRANSITION_ROUTES, true)) {
                $category = str_contains((string) $route, 'closeout-reviews.approve') ? 'billing_handoff_failure' : 'transition_failure';
            }
            $this->incidents->record(
                $request->attributes->get('organization'),
                $request->user(),
                $category,
                'error',
                null,
                $this->safeRequestContext($request, ['reason_code' => class_basename($exception)]),
                $request->attributes->get('request_id'),
            );

            throw $exception;
        }
    }

    private function safeRequestContext(Request $request, array $extra = []): array
    {
        $context = ['route' => $request->route()?->getName(), ...$extra];
        foreach (['visit', 'closeout', 'handoff', 'media'] as $parameter) {
            $value = $request->route($parameter);
            if (is_scalar($value) && ctype_digit((string) $value)) {
                $context[$parameter.'_id'] = (int) $value;
            }
        }

        return $context;
    }
}
