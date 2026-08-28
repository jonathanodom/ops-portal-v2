<?php

namespace App\Domain\Commercial;

use App\Models\CommercialRevision;
use App\Models\OrganizationCommercialSetting;

final class ApprovalPolicyEvaluator
{
    /** @return array<int, array<string, int|string|array<int,int>>> */
    public function evaluate(CommercialRevision $revision): array
    {
        $revision->loadMissing('lines');
        $settings = OrganizationCommercialSetting::query()->where('organization_id', $revision->organization_id)->firstOrFail();
        $triggers = [];
        if ($revision->gross_margin_basis_points !== null && $revision->gross_margin_basis_points < $settings->gross_margin_floor_bps) {
            $triggers[] = ['kind' => 'gross_margin_below_floor', 'actual_basis_points' => $revision->gross_margin_basis_points, 'threshold_basis_points' => $settings->gross_margin_floor_bps];
        }
        $discount = $revision->line_discount_total_cents + $revision->quote_discount_total_cents;
        $effectiveBps = $revision->subtotal_cents > 0 ? intdiv(($discount * 10000) + intdiv($revision->subtotal_cents, 2), $revision->subtotal_cents) : 0;
        if ($effectiveBps > $settings->discount_approval_ceiling_bps) {
            $triggers[] = ['kind' => 'effective_discount_above_ceiling', 'actual_basis_points' => $effectiveBps, 'threshold_basis_points' => $settings->discount_approval_ceiling_bps];
        }
        $belowCost = $revision->lines->filter(fn ($line) => $line->cost_resolved && ($line->gross_sell_cents - $line->line_discount_cents - $line->quote_discount_cents) < $line->resolved_cost_cents)->pluck('id')->values()->all();
        if ($belowCost !== []) {
            $triggers[] = ['kind' => 'below_cost_lines', 'line_ids' => $belowCost];
        }
        $overrides = $revision->lines->where('sell_price_overridden', true)->pluck('id')->values()->all();
        if ($overrides !== []) {
            $triggers[] = ['kind' => 'manual_sell_price_override', 'line_ids' => $overrides];
        }
        if ($revision->terms_overridden) {
            $triggers[] = ['kind' => 'terms_override'];
        }

        return $triggers;
    }
}
