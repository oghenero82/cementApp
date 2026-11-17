<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Ordered customer deliveries and shared-ledger operating costs, with authoritative trip rollups. */
final class NativeFleet
{
    public const TYPES = ['trip','trip_destination','fuel_transaction','vehicle_maintenance','trip_expense','expense'];
    public static function enabled(NativeRecords $r): bool
    {
        return isset(M::properties($r->context->catalog->metadata('trip'))['settings']['cementFleet']);
    }
    public static function register($registry): void
    {
        $h = new self();
        $inputs = [['name' => 'recordTypeCode','type' => 'string'],['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']];
        foreach (['prepare','effects'] as $method) {
            $registry->register('cement.fleet.'.$method, $h, $method, $inputs);
        }
    }
    public static function definition(array $d): array
    {
        $d['settings']['cementFleet'] = 1;
        $d['settings']['cementOperations'] = ['read','insert','update','delete'];
        foreach (['prepare' => 'beforeValidate','effects' => 'afterSave'] as $method => $stage) {
            $d['services'][] = ['code' => 'cement_fleet_'.$method,'service' => 'cement.fleet.'.$method,'method' => $method,'stage' => $stage,'trigger' => 'true','order' => $method === 'prepare' ? -1000 : 1000,'parameters' => ['recordTypeCode' => $d['code'],'record' => ['source' => 'data'],'previous' => ['source' => 'old'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        }return $d;
    }
    private static function stops(NativeRecords $r, int $trip): array
    {
        return array_values(array_filter($r->rows('trip_destination'), fn ($x) => $x['TRIP_ID'] === $trip));
    }
    private static function stopSnapshot(NativeRecords $r, int $id, array $stop): array
    {
        $accepted = 0;
        $rejected = 0;
        $last = null;
        foreach ($r->rows('delivery_proof') as $proof) {
            if (($proof['TRIP_DESTINATION_ID'] ?? null) === $id && $proof['STATUS'] === 'confirmed') {
                $accepted += (float)$proof['ACCEPTED_QUANTITY'];
                $rejected += (float)($proof['REJECTED_QUANTITY'] ?? 0);
                if ($last === null || [$proof['DELIVERED_DATE'],$proof['_ID']] > [$last['DELIVERED_DATE'],$last['_ID']]) {
                    $last = $proof;
                }
            }
        }
        if ($accepted + $rejected > (float)$stop['QUANTITY'] + 0.00000001) {
            throw ValidationException::field('quantity', 'Destination proof exceeds its quantity');
        }$data = ['DELIVERY_STATUS' => $accepted + 0.00000001 >= (float)$stop['QUANTITY'] ? 'delivered' : ($accepted + $rejected + 0.00000001 >= (float)$stop['QUANTITY'] ? 'completed_with_rejection' : ($accepted + $rejected > 0 ? 'partial' : 'pending'))];
        if ($last) {
            $data += ['PROOF_ID' => $last['_ID'],'DELIVERED_AT' => $last['DELIVERED_DATE']];
        }return $data;
    }
    public static function proofPlans(NativeRecords $r, array $proof): array
    {
        if (!self::enabled($r) || empty($proof['TRIP_DESTINATION_ID'])) {
            return [];
        }$stop = $r->find('trip_destination', $proof['TRIP_DESTINATION_ID']);
        $p = $r->plan('trip_destination', ['CODE' => $stop['CODE']], $stop['_ID']);
        $p['_cementGeneratedBy'] = 'cement.fleet.proof';
        return [$p];
    }
    public static function validateProof(NativeRecords $r, array $proof): void
    {
        if (!self::enabled($r)) {
            return;
        }if (empty($proof['TRIP_DESTINATION_ID'])) {
            foreach ($r->rows('trip_destination') as $stop) {
                if (($stop['SALE_ID'] ?? null) === $proof['SALE_ID']) {
                    throw ValidationException::field('destination', 'Select the assigned trip destination');
                }
            }return;
        }$stop = $r->find('trip_destination', $proof['TRIP_DESTINATION_ID']);
        $trip = $r->find('trip', $stop['TRIP_ID']);
        if ($trip['STATUS'] !== 'pending' || empty($trip['DEPARTURE_AT']) || $proof['DELIVERED_DATE'] < $trip['DEPARTURE_AT']) {
            throw ValidationException::field('trip', 'Record delivery after departure on an active trip');
        }
    }
    public static function factor(NativeRecords $r, array $stop): float
    {
        $factor = null;
        foreach ($r->rows('customer_sale_item') as $line) {
            if ($line['SALE_ID'] === $stop['SALE_ID'] && $line['PRODUCT_ID'] === $stop['PRODUCT_ID'] && $line['UNIT_ID'] === $stop['UNIT_ID']) {
                $next = (float)$line['UNIT_CONVERSION_SNAPSHOT']['factor'];
                if ($factor !== null && abs($next - $factor) > 0.00000001) {
                    throw ValidationException::field('unit', 'Sale lines require matching unit conversion snapshots for this destination');
                }$factor = $next;
            }
        }if ($factor === null || $factor <= 0) {
            throw ValidationException::field('unit', 'Destination requires a valid sale unit snapshot');
        }return $factor;
    }
    private static function totals(NativeRecords $r, int $id): array
    {
        $stops = self::stops($r, $id);
        $v = ['DESTINATION_COUNT' => count($stops),'TOTAL_QUANTITY' => array_sum(array_column($stops, 'QUANTITY')),'TOTAL_FUEL_COST' => 0,'TOTAL_MAINTENANCE_COST' => 0,'TOTAL_TRIP_EXPENSE' => 0];
        foreach (['fuel_transaction' => ['TOTAL_AMOUNT','TOTAL_FUEL_COST'],'vehicle_maintenance' => ['TOTAL_COST','TOTAL_MAINTENANCE_COST'],'trip_expense' => ['AMOUNT','TOTAL_TRIP_EXPENSE'],'expense' => ['AMOUNT','TOTAL_TRIP_EXPENSE']] as $type => [$amount,$target]) {
            foreach ($r->rows($type) as $row) {
                if (($row['TRIP_ID'] ?? null) === $id && $row['STATUS'] === 'posted') {
                    $v[$target] += NativeLedger::minor($row[$amount]);
                }
            }
        }$sum = 0;
        foreach (['TOTAL_FUEL_COST','TOTAL_MAINTENANCE_COST','TOTAL_TRIP_EXPENSE'] as $a) {
            $sum += $v[$a];
            $v[$a] = NativeLedger::decimal($v[$a]);
        }$v['TOTAL_TRIP_COST'] = NativeLedger::decimal($sum);
        return $v;
    }
    private static function positive(mixed $v): float
    {
        if (!is_numeric($v) || !is_finite((float)$v) || $v <= 0 || abs((float)$v - round((float)$v, 6)) > 0.00000001) {
            throw ValidationException::field('quantity', 'Use a positive quantity with at most six decimals');
        }return (float)$v;
    }
    public function prepare(string $recordTypeCode, array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $t = $recordTypeCode;
        $d = $r->decode($t, $record);
        $old = $r->decode($t, $previous);
        $f = $r->aliases($t);
        $origin = TrustedPlans::origin();
        if ($t === 'trip') {
            if (($old['STATUS'] ?? null) === 'completed') {
                if ($origin !== 'cement.fleet.rollup' || $operation !== 'update') {
                    throw ValidationException::field($f['STATUS'], 'Completed trip terms are immutable');
                }foreach ($old as $a => $v) {
                    if (!in_array($a, ['DESTINATION_COUNT','TOTAL_QUANTITY','TOTAL_FUEL_COST','TOTAL_MAINTENANCE_COST','TOTAL_TRIP_EXPENSE','TOTAL_TRIP_COST'], true) && ($d[$a] ?? null) != $v) {
                        throw ValidationException::field($f['STATUS'], 'Completed trip terms are immutable');
                    }
                }return ['proposedData' => $r->encode($t, self::totals($r, $recordId))];
            }
            if ($operation === 'delete') {
                if (self::stops($r, $recordId)) {
                    throw ValidationException::field($f['STATUS'], 'Remove unused destinations before deleting a trip');
                }return [];
            }
            $state = $d['STATUS'] ?? 'draft';
            if (!in_array($state, ['draft','pending','completed'], true) || (($old['STATUS'] ?? null) === 'pending' && $state === 'draft')) {
                throw ValidationException::field($f['STATUS'], 'Invalid trip transition');
            }$vehicle = $r->find('vehicle', (int)$d['VEHICLE_ID']);
            $driver = $r->find('person', (int)$d['DRIVER_ID']);
            if ($vehicle['STATUS'] !== 'active' || $driver['STATUS'] !== 'active') {
                throw ValidationException::field($f['VEHICLE_ID'], 'Choose an active vehicle and driver');
            }$stops = self::stops($r, $recordId);
            $data = self::totals($r, $recordId);
            if ($old && $old['STATUS'] === 'pending') {
                foreach (['VEHICLE_ID','DRIVER_ID','LOADING_ID','ORIGIN_DEPOT_ID','ORIGIN_LOCATION_ID','DEPARTURE_AT'] as $a) {
                    if (($old[$a] ?? null) != ($d[$a] ?? null)) {
                        throw ValidationException::field($f['STATUS'], 'Dispatched trip identity is immutable');
                    }
                }
            }
            if (!empty($d['LOADING_ID'])) {
                $load = $r->find('cement_loading', $d['LOADING_ID']);
                if ($load['STATUS'] !== 'confirmed' || (!empty($load['TRUCK_ID']) && $load['TRUCK_ID'] !== $d['VEHICLE_ID']) || (!empty($load['DRIVER_ID']) && $load['DRIVER_ID'] !== $d['DRIVER_ID'])) {
                    throw ValidationException::field($f['LOADING_ID'], 'Trip must match its confirmed loading vehicle and driver');
                }if ($data['TOTAL_QUANTITY'] > (float)$load['QUANTITY'] + 0.00000001) {
                    throw ValidationException::field($f['LOADING_ID'], 'Destinations exceed loading quantity');
                }
            }
            if ($state !== 'draft') {
                if (!$stops) {
                    throw ValidationException::field($f['STATUS'], 'Add destinations before dispatch');
                }if (!empty($d['SCHEDULED_DATE']) && $d['SCHEDULED_DATE'] > gmdate('Y-m-d')) {
                    throw ValidationException::field($f['SCHEDULED_DATE'], 'Future trips must remain draft');
                }$bags = 0;
                $tonnes = 0;
                $unknownWeight = false;
                foreach ($stops as $stop) {
                    $product = $r->find('product', $stop['PRODUCT_ID']);
                    $factor = self::factor($r, $stop);
                    if ($factor <= 0) {
                        throw ValidationException::field($f['STATUS'], 'Declare destination product unit conversion');
                    }$base = (float)$stop['QUANTITY'] * $factor;
                    $bags += $base;
                    $weight = (float)($product['PACKAGE_WEIGHT_KG'] ?? 0);
                    if ($weight <= 0) {
                        $unknownWeight = true;
                    } else {
                        $tonnes += $base * $weight / (($product['PACKAGE_SIZE'] ?? 0) > 0 ? (float)$product['PACKAGE_SIZE'] : 1) / 1000;
                    }
                }
                if ((($vehicle['CAPACITY_BAGS'] ?? 0) > 0 && $bags > (float)$vehicle['CAPACITY_BAGS'] + 0.00000001) || (($vehicle['CAPACITY_TONNES'] ?? 0) > 0 && ($unknownWeight || $tonnes > (float)$vehicle['CAPACITY_TONNES'] + 0.00000001))) {
                    throw ValidationException::field($f['VEHICLE_ID'], 'Trip exceeds vehicle capacity or needs declared package weights');
                }
                $data['DEPARTURE_AT'] = ($old['STATUS'] ?? null) === 'pending' ? $old['DEPARTURE_AT'] : gmdate('Y-m-d H:i:s');
            }
            if ($state === 'completed') {
                foreach ($stops as $stop) {
                    if (!in_array(self::stopSnapshot($r, $stop['_ID'], $stop)['DELIVERY_STATUS'], ['delivered','completed_with_rejection'], true)) {
                        throw ValidationException::field($f['STATUS'], 'Complete delivery proof for every destination first');
                    }
                }$data['ARRIVAL_AT'] = gmdate('Y-m-d H:i:s');
            }
            return ['proposedData' => $r->encode($t, $data)];
        }
        if ($t === 'trip_destination') {
            $trip = $r->find('trip', (int)$d['TRIP_ID']);
            if ($old && $old['TRIP_ID'] !== $d['TRIP_ID']) {
                throw ValidationException::field($f['TRIP_ID'], 'Destination parent is immutable');
            }$hasProof = false;
            foreach ($r->rows('delivery_proof') as $p) {
                if (($p['TRIP_DESTINATION_ID'] ?? null) === $recordId && $p['STATUS'] === 'confirmed') {
                    $hasProof = true;
                }
            }
            if ($trip['STATUS'] === 'completed' || ($hasProof && $origin !== 'cement.fleet.proof')) {
                throw ValidationException::field($f['STATUS'], 'Proven destination terms are immutable');
            }if ($operation === 'delete') {
                return [];
            }
            if ($origin === 'cement.fleet.proof') {
                foreach ($old as $a => $v) {
                    if (!in_array($a, ['DELIVERY_STATUS','DELIVERED_AT','PROOF_ID'], true) && ($d[$a] ?? null) != $v) {
                        throw ValidationException::field($f['STATUS'], 'Destination terms are immutable');
                    }
                }
            }
            if (!in_array($d['STATUS'] ?? 'draft', ['draft','active'], true)) {
                throw ValidationException::field($f['STATUS'], 'Invalid destination state');
            }$qty = self::positive($d['QUANTITY']);
            if (($d['DESTINATION_ORDER'] ?? 0) < 1) {
                throw ValidationException::field($f['DESTINATION_ORDER'], 'Use a positive destination order');
            }if (empty($d['SALE_ID']) || empty($d['PRODUCT_ID'])) {
                throw ValidationException::field($f['SALE_ID'], 'Choose a customer sale and product for delivery');
            }$sale = $r->find('customer_sales', $d['SALE_ID']);
            if ($sale['STATUS'] !== 'invoiced') {
                throw ValidationException::field($f['SALE_ID'], 'Deliver an invoiced sale');
            }if (isset($d['CUSTOMER_ID']) && $d['CUSTOMER_ID'] !== $sale['CUSTOMER_ID']) {
                throw ValidationException::field($f['CUSTOMER_ID'], 'Destination customer must match sale');
            }if (!empty($d['LOADING_ID']) && (($sale['SOURCE_LOADING_ID'] ?? null) !== $d['LOADING_ID'] || (!empty($trip['LOADING_ID']) && $trip['LOADING_ID'] !== $d['LOADING_ID']))) {
                throw ValidationException::field($f['LOADING_ID'], 'Destination loading must match its sale and trip');
            }
            if (!empty($trip['LOADING_ID']) && ($sale['SOURCE_LOADING_ID'] ?? null) !== $trip['LOADING_ID']) {
                throw ValidationException::field($f['SALE_ID'], 'Destination must belong to the trip loading');
            }
            $ordered = 0;
            foreach ($r->rows('customer_sale_item') as $line) {
                if ($line['SALE_ID'] === $sale['_ID'] && $line['PRODUCT_ID'] === $d['PRODUCT_ID'] && $line['UNIT_ID'] === $d['UNIT_ID']) {
                    $ordered += (float)$line['QUANTITY'];
                }
            }$planned = $qty;
            if (!$old) {
                foreach ($r->rows('delivery_proof') as $proof) {
                    if ($proof['SALE_ID'] === $sale['_ID'] && $proof['STATUS'] === 'confirmed' && empty($proof['TRIP_DESTINATION_ID'])) {
                        throw ValidationException::field($f['SALE_ID'], 'Create destinations before recording unassigned delivery proof');
                    }
                }
            }
            foreach ($r->rows('trip_destination') as $stop) {
                if ($stop['_ID'] !== $recordId) {
                    if ($stop['TRIP_ID'] === $trip['_ID'] && $stop['DESTINATION_ORDER'] === $d['DESTINATION_ORDER']) {
                        throw ValidationException::field($f['DESTINATION_ORDER'], 'Destination order must be unique');
                    }if (($stop['SALE_ID'] ?? null) === $sale['_ID'] && ($stop['PRODUCT_ID'] ?? null) === $d['PRODUCT_ID'] && $stop['UNIT_ID'] === $d['UNIT_ID']) {
                        $planned += (float)$stop['QUANTITY'];
                    }
                }
            }if ($planned > $ordered + 0.00000001) {
                throw ValidationException::field($f['QUANTITY'], 'Destinations exceed sale product quantity');
            }$data = ['CUSTOMER_ID' => $sale['CUSTOMER_ID']] + self::stopSnapshot($r, $recordId, $d);
            if (!$old && (isset($d['PROOF_ID']) || isset($d['DELIVERED_AT']))) {
                throw ValidationException::field($f['PROOF_ID'], 'Delivery evidence is server-owned');
            }return ['proposedData' => $r->encode($t, $data)];
        }
        if (($old['STATUS'] ?? null) === 'posted') {
            throw ValidationException::field($f['STATUS'], 'Posted costs are immutable');
        }if ($operation === 'delete') {
            return [];
        }if (!in_array($d['STATUS'] ?? 'draft', ['draft','posted'], true)) {
            throw ValidationException::field($f['STATUS'], 'Invalid cost state');
        }foreach (['JOURNAL_ID','POSTED_JOURNAL_ID'] as $a) {
            if (isset($d[$a])) {
                throw ValidationException::field($f[$a], 'Journal links are derived from authoritative sources');
            }
        }$data = [];
        $date = '';
        if ($t === 'fuel_transaction') {
            $litres = self::positive($d['LITRES']);
            $price = NativeLedger::minor($d['PRICE_PER_LITRE']);
            $minor = (int)bcdiv(bcadd(bcmul(number_format($litres, 6, '.', ''), (string)$price, 6), '0.5', 6), '1', 0);
            $data['TOTAL_AMOUNT'] = NativeLedger::decimal($minor);
            $date = $d['FUEL_DATE'];
        } elseif ($t === 'vehicle_maintenance') {
            $minor = NativeLedger::minor($d['PARTS_COST'] ?? 0) + NativeLedger::minor($d['LABOR_COST'] ?? 0);
            $data['TOTAL_COST'] = NativeLedger::decimal($minor);
            $date = $d['SERVICE_DATE'];
        } else {
            $minor = NativeLedger::minor($d['AMOUNT']);
            $date = $d['EXPENSE_DATE'];
            if ($t === 'expense' && NativeLedger::minor($d['TAX_AMOUNT'] ?? 0) > $minor) {
                throw ValidationException::field($f['TAX_AMOUNT'], 'Included tax cannot exceed the expense');
            }
        }
        $trip = null;
        if (!empty($d['TRIP_ID'])) {
            $trip = $r->find('trip', $d['TRIP_ID']);
            if (isset($d['VEHICLE_ID']) && $trip['VEHICLE_ID'] !== $d['VEHICLE_ID']) {
                throw ValidationException::field($f['VEHICLE_ID'], 'Cost vehicle must match trip');
            }
        }
        if (!empty($d['TRIP_DESTINATION_ID'])) {
            $stop = $r->find('trip_destination', $d['TRIP_DESTINATION_ID']);
            if ($stop['TRIP_ID'] !== $d['TRIP_ID'] || (!empty($d['ALLOCATED_TO_SALE_ID']) && $d['ALLOCATED_TO_SALE_ID'] !== $stop['SALE_ID'])) {
                throw ValidationException::field($f['TRIP_DESTINATION_ID'], 'Expense allocation must match the trip destination');
            }
        }
        if (!empty($d['ALLOCATED_TO_SALE_ID'])) {
            $matched = false;
            foreach (self::stops($r, $d['TRIP_ID']) as $stop) {
                if ($stop['SALE_ID'] === $d['ALLOCATED_TO_SALE_ID']) {
                    $matched = true;
                }
            }if (!$matched) {
                throw ValidationException::field($f['ALLOCATED_TO_SALE_ID'], 'Allocate to a sale on this trip');
            }
        }
        if (!empty($d['VEHICLE_ID'])) {
            $vehicle = $r->find('vehicle', $d['VEHICLE_ID']);
            if (isset($d['ODOMETER']) && ($d['ODOMETER'] < ($vehicle['ODOMETER'] ?? 0))) {
                throw ValidationException::field($f['VEHICLE_ID'], 'Vehicle odometer cannot decrease');
            }
        }
        if (($d['STATUS'] ?? 'draft') === 'posted') {
            (new NativeLedger())->authorizePosting($context);
            if ($minor <= 0 || substr($date, 0, 10) > gmdate('Y-m-d') || (strlen($date) > 10 && $date > gmdate('Y-m-d H:i:s'))) {
                throw ValidationException::field($f['STATUS'], 'Post a positive cost on a non-future date');
            }
        }
        return ['proposedData' => $r->encode($t, $data)];
    }
    public function effects(string $recordTypeCode, array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $t = $recordTypeCode;
        $d = $r->decode($t, $record);
        $plans = [];
        if ($t === 'trip') {
            return [];
        }
        if ($t === 'trip_destination') {
            $trip = $r->find('trip', $d['TRIP_ID']);
            $p = $r->plan('trip', ['CODE' => $trip['CODE']], $trip['_ID']);
            $p['_cementGeneratedBy'] = 'cement.fleet.rollup';
            $plans[] = $p;
            if ($operation !== 'delete' && !empty($d['SALE_ID'])) {
                $sale = $r->find('customer_sales', $d['SALE_ID']);
                if (empty($sale['SOURCE_TRIP_ID'])) {
                    $p = $r->plan('customer_sales', ['SOURCE_TRIP_ID' => $trip['_ID']], $sale['_ID']);
                    $p['_cementGeneratedBy'] = 'cement.fleet';
                    $plans[] = $p;
                }
            }return ['mutationPlans' => $plans];
        }
        if ($operation === 'delete' || $d['STATUS'] !== 'posted') {
            return [];
        }$settings = $r->settings();
        $amount = NativeLedger::minor($d[match($t) {
            'fuel_transaction' => 'TOTAL_AMOUNT','vehicle_maintenance' => 'TOTAL_COST',default => 'AMOUNT'
        }]);
        $debit = (int)(match($t) {
            'fuel_transaction' => $settings['cementFuelExpenseAccountId'] ?? 0,'vehicle_maintenance' => $settings['cementMaintenanceExpenseAccountId'] ?? 0,'trip_expense' => $d['ACCOUNT_ID'] ?? $settings['cementTransportExpenseAccountId'] ?? 0,default => $d['EXPENSE_ACCOUNT_ID'] ?? $settings['cementOperatingExpenseAccountId'] ?? 0
        });
        $credit = (int)($d['PAYMENT_ACCOUNT_ID'] ?? $settings['cementOperatingBankAccountId'] ?? $settings['cementReceiptBankAccountId'] ?? 0);
        if ($debit === $credit) {
            throw ValidationException::field('account', 'Expense and payment accounts must differ');
        }$plans = (new NativeLedger())->postingPlans($context, $t, $recordId, 'CEMENT-COST-'.$t.'-'.$recordId, 'Operating cost '.$d['CODE'], [[$debit,$amount,0],[$credit,0,$amount]]);
        if (!empty($d['TRIP_ID'])) {
            $trip = $r->find('trip', $d['TRIP_ID']);
            $p = $r->plan('trip', ['CODE' => $trip['CODE']], $trip['_ID']);
            $p['_cementGeneratedBy'] = 'cement.fleet.rollup';
            $plans[] = $p;
        }
        if (!empty($d['VEHICLE_ID']) && isset($d['ODOMETER'])) {
            $v = $r->find('vehicle', $d['VEHICLE_ID']);
            if ($d['ODOMETER'] > ($v['ODOMETER'] ?? 0)) {
                $p = $r->plan('vehicle', ['ODOMETER' => $d['ODOMETER']], $v['_ID']);
                $p['_cementGeneratedBy'] = 'cement.fleet';
                $plans[] = $p;
            }
        }return ['mutationPlans' => $plans];
    }
}
