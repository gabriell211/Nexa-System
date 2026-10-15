<?php
declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact unsigned decimal arithmetic without int/float casts or optional
 * native extensions. Counter totals may exceed PHP_INT_MAX over time.
 */
final class UnsignedDecimal
{
    public static function normalize(string $value): string
    {
        if (!preg_match('/^[0-9]+$/D', $value)) {
            throw new InvalidArgumentException('Invalid unsigned counter.');
        }

        return ltrim($value, '0') ?: '0';
    }

    public static function compare(string $left, string $right): int
    {
        $a = self::normalize($left);
        $b = self::normalize($right);
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    public static function add(string $left, string $right): string
    {
        $a = strrev(self::normalize($left));
        $b = strrev(self::normalize($right));
        $carry = 0;
        $digits = '';
        for ($i = 0, $n = max(strlen($a), strlen($b)); $i < $n; $i++) {
            $value = (isset($a[$i]) ? (int) $a[$i] : 0)
                + (isset($b[$i]) ? (int) $b[$i] : 0) + $carry;
            $digits .= (string) ($value % 10);
            $carry = intdiv($value, 10);
        }

        if ($carry !== 0) {
            $digits .= (string) $carry;
        }

        return strrev($digits);
    }

    public static function subtract(string $greater, string $lesser): string
    {
        if (self::compare($greater, $lesser) < 0) {
            throw new InvalidArgumentException('Negative counter delta.');
        }

        $a = strrev(self::normalize($greater));
        $b = strrev(self::normalize($lesser));
        $borrow = 0;
        $digits = '';
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $value = (int) $a[$i] - (isset($b[$i]) ? (int) $b[$i] : 0) - $borrow;
            $borrow = $value < 0 ? 1 : 0;
            $digits .= (string) ($value < 0 ? $value + 10 : $value);
        }

        return self::normalize(strrev($digits));
    }
}
