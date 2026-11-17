<?php

namespace App\Libraries\CementIntegration;

/** Deterministic stock/cost projection from immutable, confirmed movements. Money is stored in minor units. */
final class InventoryMath
{
    public static function key(int $location, int $product, string $condition): string
    {
        return $location.':'.$product.':'.$condition;
    }
    public static function project(array $movements): array
    {
        $stocks = [];
        $effects = [];
        foreach ($movements as $m) {
            if (($m['STATUS'] ?? null) !== 'confirmed') {
                continue;
            }
            $id = $m['_ID'];
            $qty = (float)($m['QTY_BASE'] ?? 0);
            $product = (int)($m['PRODUCT_ID'] ?? 0);
            $condition = $m['STOCK_CONDITION'] ?? '';
            $policy = $m['COST_POLICY'] ?? '';
            $date = $m['EFFECTIVE_AT'] ?? $m['MOVEMENT_DATE'] ?? '';
            if (!is_finite($qty) || $qty <= 0 || abs($qty - round($qty, 6)) > 0.00000001 || $product <= 0 || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $condition) || !in_array($policy, ['fifo','weighted_average'], true)) {
                throw new \InvalidArgumentException('Invalid movement quantity, product, condition or cost policy');
            }
            $source = (int)($m['SOURCE_LOCATION_ID'] ?? 0);
            $destination = (int)($m['DESTINATION_LOCATION_ID'] ?? 0);
            if ((!$source && !$destination) || ($source && $source === $destination)) {
                throw new \InvalidArgumentException('Choose a source or a different destination');
            }
            foreach (array_filter([$source,$destination]) as $location) {
                $key = self::key($location, $product, $condition);
                $stocks[$key] ??= ['LOCATION_ID' => $location,'PRODUCT_ID' => $product,'STOCK_CONDITION' => $condition,'qty' => 0.0,'minor' => 0,'layers' => [],'policy' => $policy,'last' => ''];
                $s = &$stocks[$key];
                if ($date < $s['last']) {
                    throw new \InvalidArgumentException('Backdated stock effects require controlled restatement');
                }if ($s['qty'] > 0.000001 && $s['policy'] !== $policy) {
                    throw new \InvalidArgumentException('Change valuation policy only after stock is empty');
                }$s['policy'] = $policy;
                $s['last'] = $date;
                unset($s);
            }
            $cost = 0;
            $allocations = [];
            if ($source) {
                $key = self::key($source, $product, $condition);
                $s = &$stocks[$key];
                if ($s['qty'] + 0.000001 < $qty) {
                    throw new \InvalidArgumentException('Insufficient available stock');
                }
                $remaining = $qty;
                $fifo = 0;
                foreach ($s['layers'] as &$layer) {
                    $take = min($remaining, $layer['remaining']);
                    if ($take <= 0.0000001) {
                        continue;
                    }$minor = $take + 0.000001 >= $layer['remaining'] ? $layer['remainingMinor'] : (int)round($layer['remainingMinor'] * $take / $layer['remaining']);
                    $fifo += $minor;
                    $layer['remaining'] = max(0, round($layer['remaining'] - $take, 6));
                    $layer['remainingMinor'] -= $minor;
                    $remaining -= $take;
                    $allocations[] = ['sourceMovementId' => $layer['source'],'quantity' => $take,'amountMinor' => $minor];
                    if ($remaining <= 0.000001) {
                        break;
                    }
                }unset($layer);
                if ($remaining > 0.000001) {
                    throw new \InvalidArgumentException('Cost layers do not cover the issue');
                }
                $cost = $policy === 'fifo' ? $fifo : ($qty + 0.000001 >= $s['qty'] ? $s['minor'] : (int)round($s['minor'] * $qty / $s['qty']));
                $s['qty'] = max(0, round($s['qty'] - $qty, 6));
                $s['minor'] -= $cost;
                if ($s['minor'] < 0) {
                    throw new \InvalidArgumentException('Stock value cannot become negative');
                }unset($s);
            } else {
                $unit = (float)($m['UNIT_COST'] ?? 0);
                if (!is_finite($unit) || $unit < 0 || $unit * $qty > 1e12) {
                    throw new \InvalidArgumentException('Invalid receipt cost');
                }
                $cost = (int)round($unit * $qty * 100);
            }
            if ($destination) {
                $key = self::key($destination, $product, $condition);
                $s = &$stocks[$key];
                $s['qty'] = round($s['qty'] + $qty, 6);
                $s['minor'] += $cost;
                $s['layers'][] = ['source' => $id,'original' => $qty,'remaining' => $qty,'originalMinor' => $cost,'remainingMinor' => $cost,'date' => $date];
                unset($s);
            }
            $effects[$id] = ['minor' => $cost,'unitCost' => $cost / 100 / $qty,'allocations' => $policy === 'fifo' ? $allocations : []];
        }
        return ['stocks' => $stocks,'effects' => $effects];
    }
}
