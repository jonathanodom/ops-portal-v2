<?php

namespace App\Support;

final class FixedPoint
{
    public static function quantityToMillis(string $value): int
    {
        return self::toScaledInteger($value, 3);
    }

    public static function dollarsToCents(string $value): int
    {
        return self::toScaledInteger($value, 2);
    }

    public static function percentToBasisPoints(string $value): int
    {
        return self::toScaledInteger($value, 2);
    }

    public static function quantity(int $millis): string
    {
        return self::format($millis, 3, true);
    }

    public static function dollars(int $cents): string
    {
        return self::format($cents, 2, false);
    }

    public static function percent(int $basisPoints): string
    {
        return self::format($basisPoints, 2, true);
    }

    private static function toScaledInteger(string $value, int $scale): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * (10 ** $scale))
            + (int) str_pad($decimal, $scale, '0');
    }

    private static function format(int $value, int $scale, bool $trim): string
    {
        $divisor = 10 ** $scale;
        $formatted = intdiv($value, $divisor).'.'.str_pad((string) ($value % $divisor), $scale, '0', STR_PAD_LEFT);

        return $trim ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
