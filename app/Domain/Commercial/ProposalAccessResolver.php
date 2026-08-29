<?php

namespace App\Domain\Commercial;

use App\Models\ProposalRecipient;
use App\Models\ProposalShareLink;

final class ProposalAccessResolver
{
    public function resolve(string $token): ProposalAccess
    {
        abort_unless(strlen($token) >= 64 && strlen($token) <= 120, 404);
        $hash = hash('sha256', $token);
        $recipient = ProposalRecipient::query()
            ->where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->with('publication.revision.document.opportunity.organization')
            ->first();
        if ($recipient) {
            $this->assertActiveOrganization($recipient->publication);

            return new ProposalAccess($recipient->publication, $recipient, null, $hash);
        }
        $link = ProposalShareLink::query()
            ->where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->with('publication.revision.document.opportunity.organization')
            ->firstOrFail();
        $this->assertActiveOrganization($link->publication);

        return new ProposalAccess($link->publication, null, $link, $hash);
    }

    private function assertActiveOrganization($publication): void
    {
        abort_unless($publication->revision->document->opportunity->organization->active, 404);
    }
}
