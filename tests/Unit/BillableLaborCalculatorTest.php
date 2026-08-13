<?php

namespace Tests\Unit;

use App\Domain\BillableLaborCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BillableLaborCalculatorTest extends TestCase
{
    #[DataProvider('calculations')]
    public function test_it_calculates_deterministic_billable_minutes(
        int $actual,
        int $increment,
        string $rule,
        int $minimum,
        int $expectedRounded,
        int $expectedBillable,
        int $expectedQuantityMillis,
    ): void {
        $result = (new BillableLaborCalculator)->calculate($actual, $increment, $rule, $minimum);

        $this->assertSame([
            'actual_minutes' => $actual,
            'rounded_minutes' => $expectedRounded,
            'billable_minutes' => $expectedBillable,
            'quantity_millis' => $expectedQuantityMillis,
        ], $result);
    }

    /** @return array<string, array{int, int, string, int, int, int, int}> */
    public static function calculations(): array
    {
        return [
            'zero ignores minimum' => [0, 15, 'up', 60, 0, 0, 0],
            'exact preserves minutes' => [67, 0, 'up', 0, 67, 67, 1116],
            'round up partial increment' => [67, 15, 'up', 0, 75, 75, 1250],
            'round up exact increment' => [60, 15, 'up', 0, 60, 60, 1000],
            'nearest below midpoint' => [67, 30, 'nearest', 0, 60, 60, 1000],
            'nearest midpoint rounds upward' => [75, 30, 'nearest', 0, 90, 90, 1500],
            'nearest above midpoint' => [76, 30, 'nearest', 0, 90, 90, 1500],
            'round down partial increment' => [67, 15, 'down', 0, 60, 60, 1000],
            'round down below first increment' => [7, 15, 'down', 0, 0, 0, 0],
            'minimum applies after rounding' => [22, 15, 'up', 60, 30, 60, 1000],
            'custom minimum supported' => [17, 0, 'nearest', 45, 17, 45, 750],
            'quantity uses deterministic integer truncation' => [1, 0, 'up', 0, 1, 1, 16],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_it_rejects_invalid_policy_inputs(int $actual, int $increment, string $rule, int $minimum): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BillableLaborCalculator)->calculate($actual, $increment, $rule, $minimum);
    }

    /** @return array<string, array{int, int, string, int}> */
    public static function invalidInputs(): array
    {
        return [
            'negative actual' => [-1, 15, 'up', 0],
            'unsupported increment' => [10, 10, 'up', 0],
            'unsupported rounding' => [10, 15, 'bankers', 0],
            'negative minimum' => [10, 15, 'up', -1],
        ];
    }
}
