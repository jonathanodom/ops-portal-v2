<?php

namespace App\Support\Api;

final class IdempotencyFingerprint
{
    /** @param array<string, mixed> $validatedPayload */
    public static function make(string $method, string $route, array $validatedPayload): string
    {
        $canonical = [
            'method' => strtoupper($method),
            'route' => $route,
            'payload' => self::normalize($validatedPayload),
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::normalize(...), $value);
    }
}
