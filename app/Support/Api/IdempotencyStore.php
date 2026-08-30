<?php

namespace App\Support\Api;

use App\Models\IdempotencyKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Generic idempotency-key handling for /api/v1 create endpoints, per
 * docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md §5/§14.
 *
 * Concurrency: the placeholder row is inserted (claiming the unique
 * (organization_id, route, idempotency_key) key) inside the same
 * transaction as the caller's side effect, so a duplicate concurrent
 * request fails the insert atomically before it can execute the side
 * effect a second time. The realistic JARVIS case — a client retrying
 * the same request sequentially after a timeout or dropped connection
 * — is fully idempotent. A request that arrives while a true concurrent
 * duplicate is still mid-transaction receives 409 conflict and should
 * retry; it can never observe or create a second resource.
 */
final class IdempotencyStore
{
    public function find(Organization $organization, string $route, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('organization_id', $organization->id)
            ->where('route', $route)
            ->where('idempotency_key', $key)
            ->first();
    }

    public function replay(Organization $organization, string $route, string $key, string $requestSha256): ?IdempotencyKey
    {
        $existing = $this->find($organization, $route, $key);
        if ($existing && (! $existing->request_sha256 || ! hash_equals($existing->request_sha256, $requestSha256))) {
            throw new IdempotencyKeyReusedException;
        }

        return $existing;
    }

    /**
     * @param  callable(): array{0: int, 1: mixed}  $produce  Returns [status, responseData].
     * @return array{0: int, 1: mixed, 2: bool} [status, responseData, wasReplayed]
     */
    public function once(Organization $organization, ?User $actor, string $route, string $key, string $requestSha256, callable $produce): array
    {
        try {
            [$status, $data] = DB::transaction(function () use ($organization, $actor, $route, $key, $requestSha256, $produce) {
                IdempotencyKey::query()->create([
                    'organization_id' => $organization->id,
                    'actor_id' => $actor?->id,
                    'route' => $route,
                    'idempotency_key' => $key,
                    'request_sha256' => $requestSha256,
                    'response_status' => 0,
                    'response_data' => null,
                ]);

                [$status, $data] = $produce();

                IdempotencyKey::query()
                    ->where('organization_id', $organization->id)
                    ->where('route', $route)
                    ->where('idempotency_key', $key)
                    ->update(['response_status' => $status, 'response_data' => $data]);

                return [$status, $data];
            });

            return [$status, $data, false];
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = $this->find($organization, $route, $key);
            if ($existing && (! $existing->request_sha256 || ! hash_equals($existing->request_sha256, $requestSha256))) {
                throw new IdempotencyKeyReusedException;
            }
            if ($existing && $existing->response_status > 0) {
                return [$existing->response_status, $existing->response_data, true];
            }

            throw new IdempotencyKeyInFlightException;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
