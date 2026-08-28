<?php

namespace Tests\Unit;

use App\Support\FixedPoint;
use PHPUnit\Framework\TestCase;

class FixedPointTest extends TestCase
{
    public function test_it_converts_and_formats_supported_fixed_point_values_without_floats(): void
    {
        $this->assertSame(1125, FixedPoint::quantityToMillis('1.125'));
        $this->assertSame(1234, FixedPoint::dollarsToCents('12.34'));
        $this->assertSame(825, FixedPoint::percentToBasisPoints('8.25'));
        $this->assertSame('1.125', FixedPoint::quantity(1125));
        $this->assertSame('12.34', FixedPoint::dollars(1234));
        $this->assertSame('8.25', FixedPoint::percent(825));
        $this->assertSame('1', FixedPoint::quantity(1000));
        $this->assertSame('0.00', FixedPoint::dollars(0));
    }
}
