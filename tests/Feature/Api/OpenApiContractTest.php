<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Guards against docs/openapi.yaml silently drifting from the implemented
 * routes, per .cursor/rules/ops-portal-api-development.mdc §13:
 * "Do not allow the documentation and implementation to intentionally
 * diverge." This does not validate response bodies against schemas —
 * that is a larger, separately-scoped effort (plan §13/§14) — it only
 * guarantees every registered /api/v1 route has a matching documented
 * path+method, and vice versa.
 */
class OpenApiContractTest extends TestCase
{
    public function test_the_spec_is_syntactically_valid_openapi(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
    }

    public function test_every_registered_api_v1_route_is_documented(): void
    {
        $documented = $this->documentedOperations();

        foreach ($this->registeredApiOperations() as $operation) {
            $this->assertContains(
                $operation,
                $documented,
                "Route {$operation} is not documented in docs/openapi.yaml. Add it before merging (rule §13).",
            );
        }
    }

    public function test_the_spec_does_not_document_routes_that_no_longer_exist(): void
    {
        $registered = $this->registeredApiOperations();

        foreach ($this->documentedOperations() as $operation) {
            $this->assertContains(
                $operation,
                $registered,
                "docs/openapi.yaml documents {$operation}, but no such route is registered. Remove it or fix the drift.",
            );
        }
    }

    public function test_every_operation_documents_rate_limiting_and_write_hardening(): void
    {
        $spec = $this->spec();
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $this->assertArrayHasKey('429', $operation['responses'], strtoupper($method)." {$path} must document 429.");
            }
        }

        foreach ([['/tickets', 'post'], ['/tickets/{ticket_id}', 'patch']] as [$path, $method]) {
            foreach (['400', '409', '413', '422', '429'] as $status) {
                $this->assertArrayHasKey($status, $spec['paths'][$path][$method]['responses'], strtoupper($method)." {$path} must document {$status}.");
            }
        }

        $idempotencyKey = $spec['components']['parameters']['IdempotencyKey']['schema'];
        $this->assertSame(8, $idempotencyKey['minLength']);
        $this->assertSame(128, $idempotencyKey['maxLength']);
    }

    public function test_postman_collection_covers_payload_bound_replay(): void
    {
        $collection = json_decode(
            (string) file_get_contents(base_path('docs/postman/ops-portal-v2-api-v1.postman_collection.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $encoded = json_encode($collection, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('changed body with same Idempotency-Key', $encoded);
        $this->assertStringContainsString('idempotency_key_reused', $encoded);
    }

    /**
     * @return array<int, string> e.g. "GET /customers/{param}"
     *
     * Path parameter names are normalized to `{param}` because Laravel's
     * route-binding variable name (e.g. `{customer}`) intentionally
     * differs from the plan's documented external contract name (e.g.
     * `{customer_id}`, per plan §8.1) — only the shape of the path and
     * the set of operations needs to match, not the parameter token.
     */
    private function registeredApiOperations(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/') || $route->uri() === 'api/v1')
            ->flatMap(function ($route) {
                $path = $this->normalizePath('/'.ltrim(preg_replace('#^api/v1#', '', $route->uri()), '/'));

                return collect($route->methods())
                    ->reject(fn ($method) => in_array($method, ['HEAD'], true))
                    ->map(fn ($method) => "{$method} {$path}");
            })
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> e.g. "GET /customers/{param}" */
    private function documentedOperations(): array
    {
        $spec = $this->spec();
        $operations = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $operations[] = strtoupper($method).' '.$this->normalizePath($path);
            }
        }

        return $operations;
    }

    private function normalizePath(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{param}', $path);
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        static $cached = null;

        return $cached ??= Yaml::parseFile(base_path('docs/openapi.yaml'));
    }
}
