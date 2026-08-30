<?php

namespace App\Support\Api\V1;

use App\Models\Contact;

/**
 * Shapes a Contact as the ContactSummary DTO from
 * docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md §8.2.
 *
 * The current schema stores one phone and one email per contact; both
 * are exposed as single-element (or empty) arrays to match the
 * documented contract shape without inventing multi-value storage.
 */
final class ContactSummary
{
    /** @return array<string, mixed> */
    public static function make(Contact $contact): array
    {
        return [
            'id' => (string) $contact->id,
            'customer_id' => (string) $contact->customer_id,
            'name' => $contact->name,
            'phones' => array_values(array_filter([$contact->phone])),
            'emails' => array_values(array_filter([$contact->email])),
        ];
    }
}
