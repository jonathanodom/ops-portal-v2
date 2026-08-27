<?php

namespace App\Domain\Commercial;

use App\Models\CommercialRevision;
use App\Models\CommercialRevisionLine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CommercialCalculator
{
    public function recalculate(CommercialRevision $revision): CommercialRevision
    {
        $lines = $revision->lines()->with('components')->orderBy('sort_order')->orderBy('id')->get();
        foreach ($lines as $line) {
            $included = ! $line->optional || $line->included;
            $gross = $included ? $this->roundRatio($line->quantity_millis * $line->effective_unit_sell_cents, 1000) : 0;
            $lineDiscount = $included ? $this->discount($line->discount_type, (int) $line->discount_value, $gross, 'line') : 0;
            [$costResolved, $cost] = $included ? $this->lineCost($line) : [true, 0];
            $line->forceFill(['gross_sell_cents' => $gross, 'line_discount_cents' => $lineDiscount, 'quote_discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => $gross - $lineDiscount, 'cost_resolved' => $costResolved, 'resolved_cost_cents' => $costResolved ? $cost : null])->save();
        }

        $included = $lines->filter(fn (CommercialRevisionLine $line) => (! $line->optional || $line->included) && $line->gross_sell_cents > 0)->values();
        $subtotal = (int) $included->sum('gross_sell_cents');
        $lineDiscountTotal = (int) $included->sum('line_discount_cents');
        $postLine = $subtotal - $lineDiscountTotal;
        $quoteDiscount = $this->discount($revision->discount_type, (int) $revision->discount_value, $postLine, 'quote');
        $allocations = $this->allocate($included, $quoteDiscount, $postLine);
        $taxTotal = 0;
        foreach ($included as $line) {
            $allocation = $allocations[$line->id] ?? 0;
            $taxableBase = max(0, $line->gross_sell_cents - $line->line_discount_cents - $allocation);
            $tax = ! $revision->customer_tax_exempt && $line->taxable ? $this->roundRatio($taxableBase * $revision->tax_rate_basis_points, 10000) : 0;
            $line->forceFill(['quote_discount_cents' => $allocation, 'tax_cents' => $tax, 'total_cents' => $taxableBase + $tax])->save();
            $taxTotal += $tax;
        }
        $costComplete = $included->every(fn (CommercialRevisionLine $line) => $line->cost_resolved);
        $cost = (int) $included->where('cost_resolved', true)->sum('resolved_cost_cents');
        $netSell = max(0, $postLine - $quoteDiscount);
        $profit = $costComplete ? $netSell - $cost : null;
        $margin = $profit !== null && $netSell > 0 ? $this->roundRatio($profit * 10000, $netSell) : null;
        $markup = $profit !== null && $cost > 0 ? $this->roundRatio($profit * 10000, $cost) : null;
        $revision->forceFill([
            'subtotal_cents' => $subtotal, 'line_discount_total_cents' => $lineDiscountTotal,
            'quote_discount_total_cents' => $quoteDiscount, 'tax_total_cents' => $taxTotal,
            'total_cents' => $netSell + $taxTotal, 'resolved_cost_cents' => $cost,
            'cost_complete' => $costComplete, 'gross_profit_cents' => $profit,
            'gross_margin_basis_points' => $margin, 'markup_basis_points' => $markup,
        ])->save();
        $this->allocateMilestones($revision);

        return $revision->refresh()->load(['lines.components', 'paymentMilestones']);
    }

    private function discount(?string $type, int $value, int $base, string $field): int
    {
        if ($type === null || $value === 0) {
            return 0;
        }
        if ($type === 'fixed') {
            return min($base, $value);
        }
        if ($type === 'percent' && $value <= 10000) {
            return min($base, $this->roundRatio($base * $value, 10000));
        }
        throw ValidationException::withMessages(["{$field}_discount" => 'Choose a valid discount not exceeding 100%.']);
    }

    /** @return array{bool,int} */
    private function lineCost(CommercialRevisionLine $line): array
    {
        if ($line->line_type !== 'package') {
            if ($line->cost_basis_cents === null || ! $line->cost_basis_quantity_millis) {
                return [false, 0];
            }

            return [true, $this->roundRatio($line->quantity_millis * $line->cost_basis_cents, $line->cost_basis_quantity_millis)];
        }
        $total = 0;
        foreach ($line->components as $component) {
            if (! $component->cost_resolved || $component->cost_basis_cents === null || ! $component->cost_basis_quantity_millis) {
                return [false, 0];
            }
            $quantity = $this->roundRatio($component->quantity_millis * $line->quantity_millis, 1000);
            $quantity = $this->roundRatio($quantity * (10000 + $component->waste_basis_points), 10000);
            $total += $this->roundRatio($quantity * $component->cost_basis_cents, $component->cost_basis_quantity_millis);
        }

        return [true, $total];
    }

    /** @param Collection<int,CommercialRevisionLine> $lines @return array<int,int> */
    private function allocate(Collection $lines, int $discount, int $base): array
    {
        if ($discount === 0 || $base === 0) {
            return [];
        }
        $result = [];
        $allocated = 0;
        foreach ($lines as $line) {
            $lineBase = max(0, $line->gross_sell_cents - $line->line_discount_cents);
            $result[$line->id] = intdiv($discount * $lineBase, $base);
            $allocated += $result[$line->id];
        }
        $remaining = $discount - $allocated;
        foreach ($lines as $line) {
            if ($remaining-- <= 0) {
                break;
            } $result[$line->id]++;
        }

        return $result;
    }

    private function allocateMilestones(CommercialRevision $revision): void
    {
        $milestones = $revision->paymentMilestones()->orderBy('sort_order')->orderBy('id')->get();
        if ($milestones->isEmpty()) {
            return;
        }
        $allocated = 0;
        $balancing = $milestones->firstWhere('is_balancing', true);
        foreach ($milestones as $milestone) {
            if ($milestone->is_balancing) {
                continue;
            }
            $amount = $milestone->amount_type === 'percent' ? $this->roundRatio($revision->total_cents * $milestone->amount_value, 10000) : min($revision->total_cents, $milestone->amount_value);
            $milestone->update(['allocated_cents' => $amount]);
            $allocated += $amount;
        }
        if ($balancing) {
            $balancing->update(['allocated_cents' => max(0, $revision->total_cents - $allocated)]);
        }
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        if ($denominator < 1) {
            throw ValidationException::withMessages(['calculation' => 'A pricing basis is invalid.']);
        }
        $sign = $numerator < 0 ? -1 : 1;

        return $sign * intdiv(abs($numerator) + intdiv($denominator, 2), $denominator);
    }
}
