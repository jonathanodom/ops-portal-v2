<?php

namespace App\Domain;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InvoiceCalculator
{
    public function recalculate(Invoice $invoice): Invoice
    {
        $lines = $invoice->lines()->orderBy('sort_order')->orderBy('id')->get();
        foreach ($lines as $line) {
            $subtotal = $line->included && $line->unit_price_cents !== null
                ? $this->roundRatio((int) $line->quantity_millis * (int) $line->unit_price_cents, 1000)
                : 0;
            $line->forceFill(['subtotal_cents' => $subtotal, 'discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => $subtotal])->save();
        }

        $included = $lines->where('included', true)->filter(fn (InvoiceLine $line) => $line->subtotal_cents > 0)->values();
        $subtotal = (int) $included->sum('subtotal_cents');
        $discount = $this->discountTotal($invoice, $subtotal);
        $allocations = $this->allocateDiscount($included, $discount, $subtotal);
        $taxTotal = 0;
        foreach ($included as $line) {
            $lineDiscount = $allocations[$line->id] ?? 0;
            $taxableBase = max(0, (int) $line->subtotal_cents - $lineDiscount);
            $tax = $line->taxable ? $this->roundRatio($taxableBase * (int) $invoice->tax_rate_basis_points, 10000) : 0;
            $line->forceFill([
                'discount_cents' => $lineDiscount,
                'tax_rate_basis_points' => $line->taxable ? $invoice->tax_rate_basis_points : 0,
                'tax_cents' => $tax,
                'total_cents' => $taxableBase + $tax,
            ])->save();
            $taxTotal += $tax;
        }
        $invoice->forceFill([
            'subtotal_cents' => $subtotal,
            'discount_total_cents' => $discount,
            'tax_total_cents' => $taxTotal,
            'total_cents' => max(0, $subtotal - $discount + $taxTotal),
        ])->save();

        return $invoice->refresh()->load('lines');
    }

    private function discountTotal(Invoice $invoice, int $subtotal): int
    {
        if ($invoice->discount_type === null) {
            return 0;
        }
        if ($invoice->discount_type === 'fixed') {
            return min($subtotal, (int) $invoice->discount_value);
        }
        if ($invoice->discount_type === 'percent') {
            if ($invoice->discount_value > 10000) {
                throw ValidationException::withMessages(['discount_value' => 'A percentage discount cannot exceed 100%.']);
            }

            return min($subtotal, $this->roundRatio($subtotal * (int) $invoice->discount_value, 10000));
        }

        throw ValidationException::withMessages(['discount_type' => 'Choose a valid discount type.']);
    }

    /** @param Collection<int, InvoiceLine> $lines @return array<int, int> */
    private function allocateDiscount(Collection $lines, int $discount, int $subtotal): array
    {
        if ($discount === 0 || $subtotal === 0) {
            return [];
        }
        $allocations = [];
        $allocated = 0;
        foreach ($lines as $line) {
            $amount = intdiv($discount * (int) $line->subtotal_cents, $subtotal);
            $allocations[$line->id] = $amount;
            $allocated += $amount;
        }
        $remaining = $discount - $allocated;
        foreach ($lines as $line) {
            if ($remaining-- <= 0) {
                break;
            }
            $allocations[$line->id]++;
        }

        return $allocations;
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
