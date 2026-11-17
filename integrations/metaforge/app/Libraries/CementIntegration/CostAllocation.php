<?php

namespace App\Libraries\CementIntegration;

/** Largest-remainder allocation conserves every minor unit across fractional destinations. */
final class CostAllocation
{
    public static function split(int $total, array $weights): array
    {
        if ($total < 0 || !$weights) {
            throw new \InvalidArgumentException('Invalid cost allocation');
        }$units = [];
        $sum = '0';
        foreach ($weights as $w) {
            if (!is_numeric($w) || !is_finite((float)$w) || (float)$w <= 0 || (float)$w > 1e12 || abs((float)$w - round((float)$w, 6)) > 0.00000001) {
                throw new \InvalidArgumentException('Destination quantities require at most six decimal places');
            }$u = bcmul(number_format((float)$w, 6, '.', ''), '1000000', 0);
            $units[] = $u;
            $sum = bcadd($sum, $u, 0);
        }
        $amounts = [];
        $remainders = [];
        $left = $total;
        foreach ($units as $i => $u) {
            $n = bcmul((string)$total, $u, 0);
            $amounts[$i] = (int)bcdiv($n, $sum, 0);
            $remainders[$i] = bcmod($n, $sum);
            $left -= $amounts[$i];
        }
        $order = array_keys($units);
        usort($order, fn ($a, $b) => bccomp($remainders[$b], $remainders[$a], 0) ?: $a <=> $b);
        for ($n = 0;$n < $left;$n++) {
            $amounts[$order[$n]]++;
        }
        return $amounts;
    }
}
