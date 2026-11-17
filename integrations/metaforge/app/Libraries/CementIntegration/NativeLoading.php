<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Supplier entitlement, loading allocations and clearance with immutable cost snapshots. */
final class NativeLoading
{
    public const TYPES = ['cement_loading','loading_clearance'];
    public static function register($registry): void
    {
        $h = new self();
        $inputs = [['name' => 'recordTypeCode','type' => 'string'],['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']];
        $registry->register('cement.loading.prepare', $h, 'prepare', $inputs);
        $registry->register('cement.loading.effects', $h, 'effects', [['name' => 'recordTypeCode','type' => 'string'],['name' => 'record','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']]);
    }
    public static function definition(array $d): array
    {
        $d['settings']['cementLoading'] = 1;
        $d['settings']['cementOperations'] = ['read','insert','update','delete'];
        $d['services'][] = ['code' => 'cement_loading_prepare','service' => 'cement.loading.prepare','method' => 'prepare','stage' => 'beforeValidate','trigger' => 'true','order' => -1000,'parameters' => ['recordTypeCode' => $d['code'],'record' => ['source' => 'data'],'previous' => ['source' => 'old'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        $d['services'][] = ['code' => 'cement_loading_effects','service' => 'cement.loading.effects','method' => 'effects','stage' => 'afterSave','trigger' => 'true','order' => 1000,'parameters' => ['recordTypeCode' => $d['code'],'record' => ['source' => 'data'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        return $d;
    }
    public static function entitlement(NativeRecords $r, array $item): array
    {
        $qty = (float)$item['ORIGINAL_QUANTITY'];
        $minor = NativeLedger::minor($item['AMOUNT']);
        foreach ($r->rows('purchase_adjustment') as $adjustment) {
            if (($adjustment['PURCHASE_ITEM_ID'] ?? null) === $item['_ID'] && $adjustment['STATUS'] === 'posted') {
                $qty += (float)($adjustment['QUANTITY'] ?? 0);
                $minor += (int)round((float)($adjustment['AMOUNT'] ?? 0) * 100);
            }
        }
        return [$qty,$minor];
    }
    public static function counters(NativeRecords $r, array $item): array
    {
        $loaded = 0;
        $cleared = 0;
        $delivered = 0;
        $first = null;
        $loads = [];
        foreach ($r->rows('cement_loading') as $row) {
            if ($row['PURCHASE_ITEM_ID'] === $item['_ID'] && $row['STATUS'] === 'confirmed') {
                $loaded += (float)$row['QUANTITY'];
                $delivered += (float)($row['WAREHOUSE_QUANTITY'] ?? 0);
                $loads[$row['_ID']] = true;
                $at = $row['CONFIRMED_AT'] ?? $row['LOADING_DATE'];
                $first = $first === null ? $at : min($first, $at);
            }
        }
        foreach ($r->rows('loading_clearance') as $row) {
            if ($row['PURCHASE_ITEM_ID'] === $item['_ID'] && $row['STATUS'] === 'verified') {
                $cleared += (float)$row['QUANTITY'];
            }
        }
        $sales = [];
        foreach ($r->rows('customer_sales') as $row) {
            if (isset($loads[$row['SOURCE_LOADING_ID'] ?? 0])) {
                $sales[$row['_ID']] = true;
            }
        }
        foreach ($r->rows('delivery_proof') as $row) {
            if (isset($sales[$row['SALE_ID']]) && $row['STATUS'] === 'confirmed') {
                $delivered += (float)$row['ACCEPTED_QUANTITY'];
            }
        }
        [$entitlement] = self::entitlement($r, $item);
        if ($loaded > $entitlement + 0.000001 || $cleared > $loaded + 0.000001 || $delivered > $loaded + 0.000001) {
            throw ValidationException::field('quantity', 'Entitlement counters are inconsistent');
        }
        $data = ['LOADED_QTY' => $loaded,'CLEARED_QTY' => $cleared,'DELIVERED_QTY' => $delivered,'FULLY_DELIVERED' => $delivered + 0.000001 >= $entitlement];
        if ($first) {
            $data['FIRST_LOADED_AT'] = $first;
        }return $data;
    }
    private function allocations(NativeRecords $r, array $rows): array
    {
        $lf = $r->aliases('cement_loading');
        $cf = $r->aliases('customer_sales');
        $sf = $r->aliases('customer_sale_item');
        $allowed = [$lf['QUANTITY'],$cf['CUSTOMER_ID'],$cf['WAREHOUSE_ID'],$sf['AGREED_UNIT_PRICE']];
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), $allowed)) {
                throw ValidationException::field($lf['ALLOCATION_ROWS'], 'Invalid allocation fields');
            }$rawQty = $row[$lf['QUANTITY']] ?? 0;
            if (!is_numeric($rawQty)) {
                throw ValidationException::field($lf['ALLOCATION_ROWS'], 'Invalid allocation quantity');
            }$qty = (float)$rawQty;
            $customer = $row[$cf['CUSTOMER_ID']] ?? null;
            $warehouse = $row[$cf['WAREHOUSE_ID']] ?? null;
            if (!is_finite($qty) || $qty <= 0 || (!$customer && !$warehouse) || ($customer && $warehouse) || ($customer && !is_int($customer)) || ($warehouse && !is_int($warehouse))) {
                throw ValidationException::field($lf['ALLOCATION_ROWS'], 'Allocate positive quantities to one customer or warehouse per row');
            }
            $key = $customer ? 'customer:'.$customer : 'warehouse:'.$warehouse;
            if (isset($seen[$key])) {
                throw ValidationException::field($lf['ALLOCATION_ROWS'], 'Combine quantities for the same destination');
            }$seen[$key] = true;
            $r->find($customer ? 'customer' : 'inventory_location', $customer ?: $warehouse);
            $result[] = ['qty' => $qty,'customer' => $customer,'warehouse' => $warehouse,'price' => $row[$sf['AGREED_UNIT_PRICE']] ?? null];
        }return $result;
    }
    public function prepare(string $recordTypeCode, array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $d = $r->decode($recordTypeCode, $record);
        $old = $r->decode($recordTypeCode, $previous);
        $f = $r->aliases($recordTypeCode);
        if (($old['STATUS'] ?? null) === ($recordTypeCode === 'cement_loading' ? 'confirmed' : 'verified')) {
            if ($recordTypeCode === 'cement_loading' && $operation === 'update' && TrustedPlans::origin() === 'cement.clearance') {
                foreach ($old as $alias => $value) {
                    if ($alias !== 'CLEARED_QUANTITY' && ($d[$alias] ?? null) != $value) {
                        throw ValidationException::field($f['STATUS'], 'Confirmed loading terms are immutable');
                    }
                }
                $cleared = 0;
                foreach ($r->rows('loading_clearance') as $row) {
                    if ($row['LOADING_ID'] === $recordId && $row['STATUS'] === 'verified') {
                        $cleared += (float)$row['QUANTITY'];
                    }
                }return ['proposedData' => $r->encode($recordTypeCode, ['CLEARED_QUANTITY' => $cleared])];
            }throw ValidationException::field($f['STATUS'], 'Confirmed loading and clearance records are immutable');
        }
        if ($operation === 'delete') {
            return [];
        }
        $qty = (float)($d['QUANTITY'] ?? 0);
        if (!is_finite($qty) || $qty <= 0 || abs($qty - round($qty, 6)) > 0.00000001) {
            throw ValidationException::field($f['QUANTITY'], 'Quantity must be positive with at most six decimal places');
        }
        $item = $r->find('purchaseItem', (int)$d['PURCHASE_ITEM_ID']);
        $purchase = $r->find('purchase', $item['PURCHASE_ID']);
        if ($recordTypeCode === 'loading_clearance') {
            $loading = $r->find('cement_loading', (int)$d['LOADING_ID']);
            if ($loading['PURCHASE_ITEM_ID'] !== $item['_ID']) {
                throw ValidationException::field($f['LOADING_ID'], 'Clearance must refer to this purchase item loading');
            }
            if (($d['STATUS'] ?? 'draft') === 'draft') {
                return [];
            }
            if ($d['STATUS'] !== 'verified' || $loading['STATUS'] !== 'confirmed') {
                throw ValidationException::field($f['STATUS'], 'Verify clearance only against a confirmed loading');
            }(new NativeLedger())->authorizePosting($context);
            if (($r->settings()['loadingClearingMode'] ?? 'partial') === 'full_only' && abs($qty - (float)$loading['QUANTITY']) > 0.000001) {
                throw ValidationException::field($f['QUANTITY'], 'Institution policy requires full clearance');
            }
            $sum = $qty;
            foreach ($r->rows('loading_clearance') as $row) {
                if ($row['_ID'] !== $recordId && $row['LOADING_ID'] === $loading['_ID'] && $row['STATUS'] === 'verified') {
                    $sum += (float)$row['QUANTITY'];
                }
            }if ($sum > (float)$loading['QUANTITY'] + 0.000001) {
                throw ValidationException::field($f['QUANTITY'], 'Clearance exceeds the uncleared loading quantity');
            }
            return [];
        }
        if (!in_array($d['STATUS'] ?? 'draft', ['draft','pending','confirmed'], true)) {
            throw ValidationException::field($f['STATUS'], 'Invalid loading state');
        }
        $depot = $r->find('supplier_depot', (int)$d['SUPPLIER_DEPOT_ID']);
        if ($depot['SUPPLIER_ID'] !== $purchase['SUPPLIER_ID']) {
            throw ValidationException::field($f['SUPPLIER_DEPOT_ID'], 'Depot belongs to a different supplier');
        }
        if (isset($d['UNIT_ID']) && $d['UNIT_ID'] !== $item['UNIT_ID']) {
            throw ValidationException::field($f['UNIT_ID'], 'Loading unit must match its purchase snapshot');
        }
        $factor = (float)$item['UNIT_CONVERSION_SNAPSHOT']['factor'];
        $rows = $this->allocations($r, $d['ALLOCATION_ROWS'] ?? []);
        $customer = 0;
        $warehouse = 0;
        foreach ($rows as $row) {
            if ($row['customer']) {
                $customer += $row['qty'];
            } else {
                $warehouse += $row['qty'];
            }
        }
        $data = ['QUANTITY_BASE' => $qty * $factor,'UNIT_ID' => $item['UNIT_ID'],'UNIT_CONVERSION_SNAPSHOT' => $item['UNIT_CONVERSION_SNAPSHOT'],'CUSTOMER_QUANTITY' => $customer,'WAREHOUSE_QUANTITY' => $warehouse,'CLEARED_QUANTITY' => 0];
        foreach (['SUPPLIER_PIN','SUPPLIER_BIN'] as $field) {
            if (!isset($d[$field]) && isset($purchase[$field])) {
                $data[$field] = $purchase[$field];
            }
        }
        if (($d['STATUS'] ?? 'draft') === 'confirmed') {
            (new NativeLedger())->authorizePosting($context);
            if ($purchase['STATUS'] !== 'paid' || (float)$purchase['PAID_AMOUNT'] < (float)$purchase['NET_AMOUNT'] || $purchase['NET_AMOUNT'] <= 0) {
                throw ValidationException::field($f['STATUS'], 'Fund the purchase before confirming loading');
            }
            if (!$rows || abs($customer + $warehouse - $qty) > 0.000001) {
                throw ValidationException::field($f['ALLOCATION_ROWS'], 'Allocate the complete load');
            }if ($d['LOADING_DATE'] > gmdate('Y-m-d H:i:s')) {
                throw ValidationException::field($f['LOADING_DATE'], 'Future loading must remain pending');
            }
            [$entitlement,$minor] = self::entitlement($r, $item);
            $loaded = 0;
            $allocated = 0;
            foreach ($r->rows('cement_loading') as $row) {
                if ($row['_ID'] !== $recordId && $row['PURCHASE_ITEM_ID'] === $item['_ID'] && $row['STATUS'] === 'confirmed') {
                    $loaded += (float)$row['QUANTITY'];
                    $allocated += (int)($row['_PRIVATE']['costMinor'] ?? round((float)$row['QUANTITY'] * (float)$item['NET_UNIT_COST'] * 100));
                }
            }
            if ($loaded + $qty > $entitlement + 0.000001) {
                throw ValidationException::field($f['QUANTITY'], 'Loading exceeds remaining purchase entitlement');
            }
            $cost = $loaded + $qty + 0.000001 >= $entitlement ? $minor - $allocated : min($minor - $allocated, (int)round((float)$item['NET_UNIT_COST'] * $qty * 100));
            if ($cost < 0) {
                throw ValidationException::field($f['QUANTITY'], 'Purchase cost allocation is inconsistent');
            }
            if ($customer > 0 && !isset(MetaForgeLoadingSupport::salesSettings($context)['cementSales'])) {
                throw ValidationException::field($f['ALLOCATION_ROWS'], 'Activate the verified native sales handler before customer allocations');
            }
            $data['CONFIRMED_AT'] = gmdate('Y-m-d H:i:s');
            $person = $r->actorPerson();
            if ($person !== null) {
                $data['CONFIRMED_BY'] = $person;
            } elseif (isset($d['CONFIRMED_BY'])) {
                throw ValidationException::field($f['CONFIRMED_BY'], 'Link the approving user to a staff person first');
            }$encoded = $r->encode($recordTypeCode, $data);
            $encoded['settings'] = ['cement' => ['costMinor' => $cost]];
            return ['proposedData' => $encoded];
        }
        return ['proposedData' => $r->encode($recordTypeCode, $data)];
    }
    public function effects(string $recordTypeCode, array $record, int $recordId, string $operation, MutationContext $context): array
    {
        if ($operation === 'delete') {
            return [];
        }$r = new NativeRecords($context);
        $d = $r->decode($recordTypeCode, $record);
        if ($recordTypeCode === 'loading_clearance') {
            if ($d['STATUS'] !== 'verified') {
                return [];
            }$load = $r->find('cement_loading', $d['LOADING_ID']);
            $item = $r->find('purchaseItem', $d['PURCHASE_ITEM_ID']);
            $a = $r->plan('cement_loading', ['CODE' => $load['CODE']], $load['_ID']);
            $a['_cementGeneratedBy'] = 'cement.clearance';
            $b = $r->plan('purchaseItem', ['CODE' => $item['CODE']], $item['_ID']);
            $b['_cementGeneratedBy'] = 'cement.clearance';
            return ['mutationPlans' => [$a,$b]];
        }
        if ($d['STATUS'] !== 'confirmed' || TrustedPlans::origin() === 'cement.clearance') {
            return [];
        }
        $item = $r->find('purchaseItem', $d['PURCHASE_ITEM_ID']);
        $factor = (float)$item['UNIT_CONVERSION_SNAPSHOT']['factor'];
        $rows = $this->allocations($r, $d['ALLOCATION_ROWS']);
        $cost = (int)($record['settings']['cement']['costMinor'] ?? 0);
        $costs = CostAllocation::split($cost, array_column($rows, 'qty'));
        $plans = [];
        foreach ($rows as $n => $row) {
            $allocated = $costs[$n];
            if ($row['warehouse']) {
                $qty = $row['qty'] * $factor;
                $p = $r->plan('inventory_movement', ['CODE' => 'LOAD-'.$recordId.'-WH-'.$n,'MOVEMENT_DATE' => $d['LOADING_DATE'],'MOVEMENT_TYPE' => 'supplier_receipt','PRODUCT_ID' => $item['PRODUCT_ID'],'DESTINATION_LOCATION_ID' => $row['warehouse'],'STOCK_CONDITION' => 'good','QTY_BASE' => $qty,'COST_POLICY' => $r->settings()['inventoryValuationMethod'] ?? 'weighted_average','UNIT_COST' => $allocated / 100 / $qty,'SOURCE_RECORD_TYPE_CODE' => 'cement_loading','SOURCE_RECORD_ID' => $recordId,'PURCHASE_ITEM_ID' => $item['_ID'],'LOADING_ID' => $recordId,'STATUS' => 'confirmed']);
                $p['_cementGeneratedBy'] = 'cement.loading';
                $plans[] = $p;
            } else {
                $purchase = $r->find('purchase', $item['PURCHASE_ID']);
                $saleRef = 'loadingSale'.$n;
                $saleItemRef = 'loadingLine'.$n;
                $p = $r->plan('customer_sales', ['CODE' => 'LOAD-'.$recordId.'-SALE-'.$n,'CUSTOMER_ID' => $row['customer'],'SALE_DATE' => substr($d['LOADING_DATE'], 0, 10),'SOURCE_LOADING_ID' => $recordId,'CURRENCY' => $purchase['CURRENCY'],'STATUS' => 'draft'], null, ['ownershipPolicy' => $purchase['OWNERSHIP_TRANSFER_POLICY']]);
                $p['_cementClientRef'] = $saleRef;
                $p['_cementGeneratedBy'] = 'cement.loading';
                $plans[] = $p;
                $data = ['CODE' => 'LOAD-'.$recordId.'-ITEM-'.$n,'PRODUCT_ID' => $item['PRODUCT_ID'],'UNIT_ID' => $item['UNIT_ID'],'QUANTITY' => $row['qty'],'SOURCE_PURCHASE_ITEM_ID' => $item['_ID'],'SOURCE_LOADING_ID' => $recordId,'STATUS' => 'draft'];
                if ($row['price'] !== null) {
                    $data['AGREED_UNIT_PRICE'] = $row['price'];
                }
                $p = $r->plan('customer_sale_item', $data, null, ['costMinor' => $allocated]);
                $p['_cementReferences'][$r->aliases('customer_sale_item')['SALE_ID']] = $saleRef;
                $p['_cementClientRef'] = $saleItemRef;
                $p['_cementGeneratedBy'] = 'cement.loading';
                $plans[] = $p;
                $p = $r->plan('customer_sales', ['STATUS' => 'invoiced']);
                $p['operation'] = 'update';
                $p['_cementRecordRef'] = $saleRef;
                $p['_cementGeneratedBy'] = 'cement.loading';
                $plans[] = $p;
            }
        }
        $counter = $r->plan('purchaseItem', ['CODE' => $item['CODE']], $item['_ID']);
        $counter['_cementGeneratedBy'] = 'cement.loading';
        $plans[] = $counter;
        return ['mutationPlans' => $plans];
    }
}
/** Read-only capability lookup kept separate from customer posting implementation. */
final class MetaForgeLoadingSupport
{
    public static function salesSettings(MutationContext $context): array
    {
        return \App\Libraries\MetaForge\Catalog\Metadata::properties($context->catalog->metadata('customer_sales'))['settings'] ?? [];
    }
}
