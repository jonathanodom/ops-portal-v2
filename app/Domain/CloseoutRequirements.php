<?php

namespace App\Domain;

final class CloseoutRequirements
{
    /** @return list<string> */
    public function narrativeFields(?string $purpose, ?string $outcome): array
    {
        if (! in_array($outcome, ['resolved', 'needs_return_trip'], true)) {
            return [];
        }

        return match (ServiceTicketPurpose::canonical($purpose)) {
            ServiceTicketPurpose::SERVICE_VISIT => ['diagnosis', 'work_performed'],
            ServiceTicketPurpose::SITE_SURVEY,
            ServiceTicketPurpose::INSTALLATION_PROJECT,
            ServiceTicketPurpose::WARRANTY_MAINTENANCE,
            ServiceTicketPurpose::INTERNAL_TESTING => ['work_performed'],
            default => ['diagnosis', 'work_performed'],
        };
    }

    /** @return list<string> */
    public function returnTripFields(?string $outcome): array
    {
        return $outcome === 'needs_return_trip' ? ['return_reason'] : [];
    }
}
