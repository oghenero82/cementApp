<?php

namespace App\Libraries\CementIntegration;

/** Exact posted-value apportionment; the last fulfilment consumes every remaining cent. */
final class SalesMath
{
    public static function portion(int $total, float $accepted, float $quantity): int
    {
        if ($total < 0 || !is_finite($accepted) || !is_finite($quantity) || $quantity <= 0 || $accepted < 0 || $accepted > $quantity + 0.000001) {
            throw new \InvalidArgumentException('Invalid fulfilment proportion');
        }
        if ($accepted + 0.000001 >= $quantity) {
            return $total;
        }
        return (int)bcdiv(bcadd(bcmul((string)$total, number_format($accepted, 6, '.', ''), 8), '0', 8), number_format($quantity, 6, '.', ''), 0);
    }
    public static function line(array $d, float $factor): array
    {
        $qty = (float)($d['QUANTITY'] ?? 0);
        $price = $d['AGREED_UNIT_PRICE'] ?? null;
        $discount = (float)($d['DISCOUNT_VALUE'] ?? 0);
        $kind = $d['DISCOUNT_TYPE'] ?? 'fixed';
        $rate = (float)($d['TAX_RATE'] ?? 0);
        if (!is_finite($qty) || $qty <= 0 || abs($qty - round($qty, 6)) > 0.00000001 || $factor <= 0 || !is_numeric($price) || !is_finite((float)$price) || $price < 0 || !is_finite($discount) || $discount < 0 || !in_array($kind, ['fixed','percent'], true) || ($kind === 'percent' && $discount > 100) || !is_finite($rate) || $rate < 0 || $rate > 100) {
            throw new \InvalidArgumentException('Invalid sale quantity, price, discount or tax');
        }
        $net = round($kind === 'percent' ? (float)$price * (1 - $discount / 100) : (float)$price - $discount, 2);
        if ($net < 0) {
            throw new \InvalidArgumentException('Discount exceeds price');
        }
        $subtotal = (int)round($net * $qty * 100);
        $tax = (int)round($subtotal * $rate / 100);
        return ['QUANTITY_BASE' => $qty * $factor,'NET_UNIT_PRICE' => NativeLedger::decimal((int)round($net * 100)),'TAX_AMOUNT' => NativeLedger::decimal($tax),'LINE_AMOUNT' => NativeLedger::decimal($subtotal + $tax),'UNIT_CONVERSION_SNAPSHOT' => ['factor' => $factor]];
    }
}
