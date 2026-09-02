<?php

namespace App\Domain;

final class CloseoutRequirements
{
    /** @return list<string> */
    public function narrativeFields(?string $purpose, ?string $outcome): array
    {
        return match (ServiceTicketPurpose::canonical($purpose)) {
            ServiceTicketPurpose::SERVICE_VISIT => in_array($outcome, ['resolved', 'needs_return_trip', 'on_hold'], true)
                ? ['diagnosis', 'work_performed']
                : [],
            ServiceTicketPurpose::SITE_SURVEY,
            ServiceTicketPurpose::INSTALLATION_PROJECT,
            ServiceTicketPurpose::WARRANTY_MAINTENANCE,
            ServiceTicketPurpose::INTERNAL_TESTING => in_array($outcome, ['resolved', 'needs_return_trip'], true)
                ? ['work_performed']
                : [],
            default => in_array($outcome, ['resolved', 'needs_return_trip'], true)
                ? ['diagnosis', 'work_performed']
                : [],
        };
    }

    /** @return list<string> */
    public function returnTripFields(?string $outcome): array
    {
        return $outcome === 'needs_return_trip' ? ['return_reason'] : [];
    }
}
