<?php

namespace App\Domain;

final class ServiceTicketPurpose
{
    public const SITE_SURVEY = 'site_survey';

    public const INSTALLATION_PROJECT = 'installation_project';

    public const SERVICE_VISIT = 'service_call';

    public const WARRANTY_MAINTENANCE = 'warranty';

    public const INTERNAL_TESTING = 'internal_test';

    /** @return array<string, string> */
    public static function supported(): array
    {
        return config('service_tickets.purposes');
    }

    /** @return array<string, string> */
    public static function legacy(): array
    {
        return config('service_tickets.legacy_purposes', []);
    }

    public static function canonical(?string $purpose): string
    {
        $purpose ??= self::SERVICE_VISIT;

        return config('service_tickets.purpose_aliases.'.$purpose, $purpose);
    }

    public static function label(?string $purpose): string
    {
        $purpose ??= self::SERVICE_VISIT;

        return self::supported()[$purpose]
            ?? self::legacy()[$purpose]
            ?? str($purpose)->replace('_', ' ')->headline()->toString();
    }

    public static function isLegacy(?string $purpose): bool
    {
        return $purpose !== null && array_key_exists($purpose, self::legacy());
    }

    /** @return array<string, string> */
    public static function selectable(?string $currentPurpose = null): array
    {
        $purposes = self::supported();
        if (self::isLegacy($currentPurpose)) {
            $purposes[$currentPurpose] = self::label($currentPurpose);
        }

        return $purposes;
    }
}
