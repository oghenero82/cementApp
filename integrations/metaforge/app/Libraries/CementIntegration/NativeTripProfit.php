<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Mutation\MutationContext;

/** Recognised goods margin and exact transport allocations across all destinations. */
final class NativeTripProfit
{
    public const SOURCES = ['trip','trip_destination','customer_sales','customer_sale_item','trip_expense','fuel_transaction','vehicle_maintenance','expense','inventory_movement','delivery_proof','customer_sale_return'];
    private static function shares(int $minor, array $weights): array
    {
        $positive = [];
        foreach ($weights as $key => $weight) {
            if ($weight > 0) {
                $positive[$key] = $weight;
            }
        }$result = array_fill_keys(array_keys($weights), 0);
        if (!$positive) {
            return $result;
        }$split = CostAllocation::split($minor, array_values($positive));
        foreach (array_keys($positive) as $n => $key) {
            $result[$key] = $split[$n];
        }return $result;
    }
    public static function generate(MutationContext $ctx, array $query): array
    {
        if (($query['code'] ?? null) !== 'cement.trip_profit') {
            throw new \InvalidArgumentException('This native report is not enabled');
        }if (array_diff(array_keys($query), ['code','from','to','entityType','entityId'])) {
            throw new \InvalidArgumentException('Unsupported report filters');
        }
        foreach (['from','to'] as $key) {
            if (!empty($query[$key]) && (!is_string($query[$key]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $query[$key]) || gmdate('Y-m-d', strtotime($query[$key].' UTC')) !== $query[$key])) {
                throw new \InvalidArgumentException('Choose valid report dates');
            }
        }if (!empty($query['from']) && !empty($query['to']) && $query['from'] > $query['to']) {
            throw new \InvalidArgumentException('Report start must precede its end');
        }if (isset($query['entityType']) && ($query['entityType'] !== 'trip' || !ctype_digit((string)($query['entityId'] ?? '')))) {
            throw new \InvalidArgumentException('Scope trip profitability to a valid trip');
        }
        $r = new NativeRecords($ctx);
        if (!NativeFleet::enabled($r)) {
            throw new \RuntimeException('Native fleet reporting is not installed');
        }$data = [];
        foreach (self::SOURCES as $type) {
            $data[$type] = $r->rows($type);
        }$sales = array_column($data['customer_sales'], null, '_ID');
        $trips = array_column($data['trip'], null, '_ID');
        $stops = array_column($data['trip_destination'], null, '_ID');
        ksort($stops);
        $accepted = [];
        foreach ($data['delivery_proof'] as $proof) {
            if ($proof['STATUS'] === 'confirmed' && !empty($proof['TRIP_DESTINATION_ID'])) {
                $accepted[$proof['TRIP_DESTINATION_ID']] = ($accepted[$proof['TRIP_DESTINATION_ID']] ?? 0) + (float)$proof['ACCEPTED_QUANTITY'];
            }
        }$groups = [];
        foreach ($data['customer_sale_item'] as $line) {
            $sale = $sales[$line['SALE_ID']] ?? null;
            if (!$sale || $sale['STATUS'] !== 'invoiced') {
                continue;
            }$key = $line['SALE_ID'].':'.$line['PRODUCT_ID'].':'.$line['UNIT_ID'];
            $groups[$key] ??= ['gross' => 0,'cost' => 0,'quantity' => 0,'sale' => $sale];
            $fulfilled = (float)($sale['_PRIVATE']['fulfilled'][$line['_ID']] ?? 0);
            [$gross,$tax] = SalesMath::recognition(NativeLedger::minor($line['LINE_AMOUNT']), NativeLedger::minor($line['TAX_AMOUNT']), $fulfilled, (float)$line['QUANTITY']);
            $groups[$key]['gross'] += $gross - $tax;
            $groups[$key]['quantity'] += $fulfilled;
            if (!empty($line['SOURCE_LOADING_ID'])) {
                $groups[$key]['cost'] += SalesMath::portion((int)($line['_PRIVATE']['costMinor'] ?? 0), $fulfilled, (float)$line['QUANTITY']);
            } else {
                foreach ($data['inventory_movement'] as $m) {
                    if (($m['SALE_ITEM_ID'] ?? null) === $line['_ID'] && $m['STATUS'] === 'confirmed') {
                        $cost = NativeLedger::minor($m['TOTAL_COST']);
                        if (!empty($m['SOURCE_LOCATION_ID']) && ($m['SOURCE_RECORD_TYPE_CODE'] ?? null) === 'customer_sale_item') {
                            $groups[$key]['cost'] += $cost;
                        } elseif (!empty($m['DESTINATION_LOCATION_ID']) && ($m['SOURCE_RECORD_TYPE_CODE'] ?? null) === 'customer_sale_return') {
                            $groups[$key]['cost'] -= $cost;
                        }
                    }
                }
            }
            foreach ($data['customer_sale_return'] as $ret) {
                if ($ret['SALE_ITEM_ID'] === $line['_ID'] && $ret['STATUS'] === 'confirmed') {
                    $groups[$key]['gross'] -= (int)$ret['_PRIVATE']['creditMinor'] - (int)$ret['_PRIVATE']['taxMinor'];
                    if (!empty($line['SOURCE_LOADING_ID']) && in_array($ret['DISPOSITION'], ['restock','damaged'], true)) {
                        $groups[$key]['cost'] -= (int)$ret['_PRIVATE']['costMinor'];
                    }
                }
            }
        }
        $revenueByStop = [];
        $goodsByStop = [];
        foreach ($groups as $key => $group) {
            if ($group['gross'] < 0 || $group['cost'] < 0) {
                throw new \RuntimeException('Trip margin snapshots are inconsistent');
            }$weights = [];
            $assigned = 0;
            foreach ($stops as $stopId => $stop) {
                if (($stop['SALE_ID'] ?? 0).':'.($stop['PRODUCT_ID'] ?? 0).':'.$stop['UNIT_ID'] === $key) {
                    $owned = $group['sale']['OWNERSHIP_TRANSFER_POLICY'] === 'loading-transfer' ? (float)$stop['QUANTITY'] : (float)($accepted[$stopId] ?? 0);
                    $weights[$stopId] = $owned;
                    $assigned += $owned;
                }
            }
            $unassigned = max(0, round($group['quantity'] - $assigned, 6));
            if ($unassigned > 0) {
                $weights['unassigned'] = $unassigned;
            }if ($assigned > $group['quantity'] + 0.00000001) {
                throw new \RuntimeException('Trip destinations exceed recognized sale quantities');
            }$revenueByStop += self::shares($group['gross'], $weights);
            $goodsByStop += self::shares($group['cost'], $weights);
        }
        $rows = [];
        foreach ($trips as $tripId => $trip) {
            if (isset($query['entityType']) && $tripId !== (int)$query['entityId']) {
                continue;
            }$route = array_filter($stops, fn ($s) => $s['TRIP_ID'] === $tripId);
            uasort($route, fn ($a, $b) => [$a['DESTINATION_ORDER'],$a['_ID']] <=> [$b['DESTINATION_ORDER'],$b['_ID']]);
            $costs = [];
            $total = 0;
            $costDates = [];
            foreach (['fuel_transaction' => 'TOTAL_AMOUNT','vehicle_maintenance' => 'TOTAL_COST','trip_expense' => 'AMOUNT','expense' => 'AMOUNT'] as $type => $field) {
                foreach ($data[$type] as $cost) {
                    if (($cost['TRIP_ID'] ?? null) === $tripId && $cost['STATUS'] === 'posted') {
                        $amount = NativeLedger::minor($cost[$field]);
                        $total += $amount;
                        $costs[] = [$type,$cost,$amount];
                        $costDates[] = substr($cost['FUEL_DATE'] ?? $cost['SERVICE_DATE'] ?? $cost['EXPENSE_DATE'], 0, 10);
                    }
                }
            }
            sort($costDates);
            $date = $trip['SCHEDULED_DATE'] ?? (isset($trip['DEPARTURE_AT']) ? substr($trip['DEPARTURE_AT'], 0, 10) : ($costDates[0] ?? null));
            if ($date && ((!empty($query['from']) && $date < $query['from']) || (!empty($query['to']) && $date > $query['to']))) {
                continue;
            }
            if (!$route) {
                if ($total > 0) {
                    $rows[] = ['Trip' => $trip['CODE'],'Stop' => 'unallocated','Customer' => '','Net sales' => 0,'Goods cost' => 0,'Allocated transport cost' => $total / 100,'Contribution after transport' => -$total / 100];
                }continue;
            }
            $weights = [];
            foreach ($route as $stopId => $stop) {
                $weights[$stopId] = round((float)$stop['QUANTITY'] * NativeFleet::factor($r, $stop), 6);
            }$alloc = array_fill_keys(array_keys($route), 0);
            $common = $total;
            foreach ($costs as [$type,$cost,$amount]) {
                if ($type !== 'trip_expense') {
                    continue;
                }$selected = [];
                if (!empty($cost['TRIP_DESTINATION_ID'])) {
                    if (!isset($route[$cost['TRIP_DESTINATION_ID']])) {
                        throw new \RuntimeException('Trip cost destination is unavailable');
                    }$selected[$cost['TRIP_DESTINATION_ID']] = $weights[$cost['TRIP_DESTINATION_ID']];
                } elseif (!empty($cost['ALLOCATED_TO_SALE_ID'])) {
                    foreach ($route as $stopId => $stop) {
                        if (($stop['SALE_ID'] ?? null) === $cost['ALLOCATED_TO_SALE_ID']) {
                            $selected[$stopId] = $weights[$stopId];
                        }
                    }
                }if (!$selected) {
                    continue;
                }foreach (self::shares($amount, $selected) as $stopId => $part) {
                    $alloc[$stopId] += $part;
                }$common -= $amount;
            }
            foreach (self::shares($common, $weights) as $stopId => $part) {
                $alloc[$stopId] += $part;
            }
            foreach ($route as $stopId => $stop) {
                $revenue = $revenueByStop[$stopId] ?? 0;
                $goods = $goodsByStop[$stopId] ?? 0;
                $transport = $alloc[$stopId];
                $rows[] = ['Trip' => $trip['CODE'],'Stop' => $stop['DESTINATION_ORDER'],'Customer' => $stop['CUSTOMER_ID'] ?? '','Net sales' => $revenue / 100,'Goods cost' => $goods / 100,'Allocated transport cost' => $transport / 100,'Contribution after transport' => ($revenue - $goods - $transport) / 100];
            }
        }
        return ['title' => 'Trip Profitability by Destination','columns' => ['Trip','Stop','Customer','Net sales','Goods cost','Allocated transport cost','Contribution after transport'],'rows' => $rows,'rowCount' => count($rows),'generatedAt' => gmdate('c')];
    }
}
