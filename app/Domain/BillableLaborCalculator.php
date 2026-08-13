<?php

namespace App\Domain;

use InvalidArgumentException;

class BillableLaborCalculator
{
    public const INCREMENTS = [0, 15, 30, 60];

    public const ROUNDING_RULES = ['up', 'nearest', 'down'];

    /** @return array{actual_minutes: int, rounded_minutes: int, billable_minutes: int, quantity_millis: int} */
    public function calculate(int $actualMinutes, int $incrementMinutes, string $roundingRule, int $minimumBillableMinutes): array
    {
        if ($actualMinutes < 0) {
            throw new InvalidArgumentException('Actual minutes cannot be negative.');
        }
        if (! in_array($incrementMinutes, self::INCREMENTS, true)) {
            throw new InvalidArgumentException('Choose a supported labor billing increment.');
        }
        if (! in_array($roundingRule, self::ROUNDING_RULES, true)) {
            throw new InvalidArgumentException('Choose a supported labor rounding rule.');
        }
        if ($minimumBillableMinutes < 0) {
            throw new InvalidArgumentException('Minimum billable minutes cannot be negative.');
        }
        if ($actualMinutes === 0) {
            return $this->result(0, 0, 0);
        }

        $roundedMinutes = $incrementMinutes === 0
            ? $actualMinutes
            : match ($roundingRule) {
                'up' => intdiv($actualMinutes + $incrementMinutes - 1, $incrementMinutes) * $incrementMinutes,
                'nearest' => intdiv(($actualMinutes * 2) + $incrementMinutes, $incrementMinutes * 2) * $incrementMinutes,
                'down' => intdiv($actualMinutes, $incrementMinutes) * $incrementMinutes,
            };
        $billableMinutes = max($roundedMinutes, $minimumBillableMinutes);

        return $this->result($actualMinutes, $roundedMinutes, $billableMinutes);
    }

    /** @return array{actual_minutes: int, rounded_minutes: int, billable_minutes: int, quantity_millis: int} */
    private function result(int $actualMinutes, int $roundedMinutes, int $billableMinutes): array
    {
        return [
            'actual_minutes' => $actualMinutes,
            'rounded_minutes' => $roundedMinutes,
            'billable_minutes' => $billableMinutes,
            'quantity_millis' => intdiv($billableMinutes * 1000, 60),
        ];
    }
}
