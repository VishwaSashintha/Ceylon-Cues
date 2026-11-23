<?php

declare(strict_types=1);

namespace CeylonCues\Core;

/**
 * Monetary arithmetic helper.
 *
 * # Precision rules
 * - All monetary values stored in MySQL use:
 *     DECIMAL(14,2)  for order/payment totals, tax amounts, running balances
 *     DECIMAL(12,2)  for unit prices, line-item subtotals
 * - PHP rounding ALWAYS uses PHP_ROUND_HALF_UP via MoneyHelper::round().
 * - Never use floatval/round() directly on money; use this class instead.
 */
final class MoneyHelper
{
    /**
     * Round a monetary value to 2 decimal places using HALF_UP convention.
     */
    public static function round(float $amount): float
    {
        return round($amount, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * Add two monetary values, returning a rounded result.
     */
    public static function add(float $a, float $b): float
    {
        return self::round($a + $b);
    }

    /**
     * Subtract b from a, returning a rounded result.
     */
    public static function subtract(float $a, float $b): float
    {
        return self::round($a - $b);
    }

    /**
     * Multiply a monetary value by a scalar (e.g. quantity or rate).
     */
    public static function multiply(float $amount, float $multiplier): float
    {
        return self::round($amount * $multiplier);
    }

    /**
     * Calculate VAT amount from a gross price and a percentage rate.
     *
     * @param float $gross      Gross (tax-inclusive) amount
     * @param float $ratePercent  e.g. 18.0 for 18 %
     */
    public static function vatAmount(float $gross, float $ratePercent): float
    {
        if ($ratePercent <= 0) {
            return 0.00;
        }
        return self::round($gross * $ratePercent / (100 + $ratePercent));
    }

    /**
     * Format a monetary value as a human-readable string.
     *
     * @param float  $amount
     * @param string $symbol  e.g. 'Rs.'
     */
    public static function format(float $amount, string $symbol = 'Rs.'): string
    {
        return $symbol . ' ' . number_format(self::round($amount), 2);
    }
}
