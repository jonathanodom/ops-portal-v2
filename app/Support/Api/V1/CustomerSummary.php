<?php

namespace App\Support\Api\V1;

use App\Models\Customer;

/**
 * Shapes a Customer as the CustomerSummary DTO from
 * docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md §8.1.
 *
 * Note: IDs are the existing numeric primary keys (returned as strings),
 * not opaque prefixed IDs. Introducing opaque IDs would require a
 * schema-wide change out of scope for this work package; see
 * docs/OP_API_0_REPOSITORY_ASSESSMENT.md.
 */
final class CustomerSummary
{
    /** @return array<string, mixed> */
    public static function make(Customer $customer): array
    {
        return [
            'id' => (string) $customer->id,
            'name' => $customer->display_name,
            'status' => $customer->status,
            'primary_phone' => $customer->phone,
            'primary_email' => $customer->email,
        ];
    }
}
