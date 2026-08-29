<?php

namespace App\Domain\Commercial;

use Illuminate\Validation\ValidationException;

final class ProposalSelectionCalculator
{
    /** @param array<string,mixed> $snapshot @param array<int,bool> $selections @return array<string,mixed> */
    public function calculate(array $snapshot, array $selections): array
    {
        $pricing = $snapshot['pricing'] ?? null;
        if (! is_array($pricing)) {
            throw ValidationException::withMessages(['options' => 'This historical publication does not support option recalculation. Publish a new revision.']);
        }
        $lines = [];
        foreach ($snapshot['lines'] as $index => $line) {
            $id = (int) $line['id'];
            $included = ! $line['optional'] || ($selections[$id] ?? (bool) $line['included']);
            $gross = $included ? $this->roundRatio((int) $line['quantity_millis'] * (int) $line['effective_unit_sell_cents'], 1000) : 0;
            $lineDiscount = $included ? $this->discount($line['discount_type'] ?? null, (int) ($line['discount_value'] ?? 0), $gross) : 0;
            $lines[] = [...$line, 'stable_order' => $index, 'included' => $included, 'gross_sell_cents' => $gross, 'line_discount_cents' => $lineDiscount, 'quote_discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => max(0, $gross - $lineDiscount)];
        }
        $includedIndexes = array_keys(array_filter($lines, fn ($line) => $line['included'] && $line['gross_sell_cents'] > 0));
        $subtotal = array_sum(array_map(fn ($index) => $lines[$index]['gross_sell_cents'], $includedIndexes));
        $lineDiscount = array_sum(array_map(fn ($index) => $lines[$index]['line_discount_cents'], $includedIndexes));
        $postLine = $subtotal - $lineDiscount;
        $quoteDiscount = $this->discount($pricing['discount_type'] ?? null, (int) ($pricing['discount_value'] ?? 0), $postLine);
        $allocated = 0;
        foreach ($includedIndexes as $index) {
            $base = max(0, $lines[$index]['gross_sell_cents'] - $lines[$index]['line_discount_cents']);
            $allocation = $quoteDiscount && $postLine ? intdiv($quoteDiscount * $base, $postLine) : 0;
            $lines[$index]['quote_discount_cents'] = $allocation;
            $allocated += $allocation;
        }
        $remaining = $quoteDiscount - $allocated;
        foreach ($includedIndexes as $index) {
            if ($remaining <= 0) {
                break;
            }
            $lines[$index]['quote_discount_cents']++;
            $remaining--;
        }
        $tax = 0;
        foreach ($includedIndexes as $index) {
            $base = max(0, $lines[$index]['gross_sell_cents'] - $lines[$index]['line_discount_cents'] - $lines[$index]['quote_discount_cents']);
            $lineTax = ! $pricing['customer_tax_exempt'] && $lines[$index]['taxable'] ? $this->roundRatio($base * (int) $pricing['tax_rate_basis_points'], 10000) : 0;
            $lines[$index]['tax_cents'] = $lineTax;
            $lines[$index]['total_cents'] = $base + $lineTax;
            $tax += $lineTax;
        }
        $total = $postLine - $quoteDiscount + $tax;

        return ['lines' => $lines, 'subtotal_cents' => $subtotal, 'line_discount_cents' => $lineDiscount, 'quote_discount_cents' => $quoteDiscount, 'discount_cents' => $lineDiscount + $quoteDiscount, 'tax_cents' => $tax, 'total_cents' => $total];
    }

    private function discount(?string $type, int $value, int $base): int
    {
        return match ($type) {
            null => 0,
            'fixed' => min($base, $value),
            'percent' => min($base, $this->roundRatio($base * $value, 10000)),
            default => throw ValidationException::withMessages(['options' => 'The frozen discount basis is invalid.']),
        };
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
