<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared JSON envelope for /api/v1, per
 * docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md §5.
 */
final class ApiResponse
{
    public static function success(Request $request, mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => ['request_id' => self::requestId($request), ...$meta],
        ], $status);
    }

    /** @param array<string, mixed>|null $details */
    public static function error(
        Request $request,
        string $code,
        string $message,
        int $status,
        ?array $details = null,
    ): JsonResponse {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'meta' => ['request_id' => self::requestId($request)],
        ], $status);
    }

    private static function requestId(Request $request): string
    {
        return (string) ($request->attributes->get('request_id') ?? $request->header('X-Request-ID') ?? '');
    }
}
