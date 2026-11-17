<?php

namespace App\Libraries\CementIntegration;

/** Exact incentive arithmetic on authoritative purchase/loading basis values. */
final class BenefitMath
{
    public static function amount(float $quantity, int $basisMinor, string $kind, float $rate, int $fixedMinor): int
    {
        if (!is_finite($quantity) || $quantity < 0 || !is_finite($rate) || $rate < 0 || abs($quantity - round($quantity, 6)) > 0.00000001 || abs($rate - round($rate, 6)) > 0.00000001 || $basisMinor < 0 || $fixedMinor < 0 || ($kind === 'percent' && $rate > 100)) {
            throw new \InvalidArgumentException('Invalid incentive basis or rate');
        }
        $q = number_format($quantity, 6, '.', '');
        $r = number_format($rate, 6, '.', '');
        $raw = match($kind) {
            'percent' => bcdiv(bcmul((string)$basisMinor, $r, 12), '100', 12),'per_quantity' => bcmul(bcmul($q, $r, 12), '100', 12),'fixed' => (string)$fixedMinor,default => throw new \InvalidArgumentException('Unsupported incentive calculation')
        };
        $rounded = bcdiv(bcadd($raw, '0.5', 12), '1', 0);
        if (bccomp($rounded, '100000000000000', 0) > 0) {
            throw new \InvalidArgumentException('Incentive exceeds the supported amount');
        }return (int)$rounded;
    }
}
