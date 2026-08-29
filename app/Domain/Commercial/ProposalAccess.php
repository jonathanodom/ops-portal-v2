<?php

namespace App\Domain\Commercial;

use App\Models\ProposalPublication;
use App\Models\ProposalRecipient;
use App\Models\ProposalShareLink;

final readonly class ProposalAccess
{
    public function __construct(
        public ProposalPublication $publication,
        public ?ProposalRecipient $recipient,
        public ?ProposalShareLink $shareLink,
        public string $tokenHash,
    ) {}

    public function recipientId(): ?int
    {
        return $this->recipient?->id;
    }

    public function shareLinkId(): ?int
    {
        return $this->shareLink?->id;
    }
}
