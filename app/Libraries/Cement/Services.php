<?php

namespace App\Libraries\Cement;

/** All effects run inside Mutation's SQL transaction. Browser state never posts ledgers. */
final class Services
{
    public const OWNED = [
        'purchase' => ['GROSS_AMOUNT','DISCOUNT_AMOUNT','NET_AMOUNT','PAID_AMOUNT'],
        'purchaseItem' => ['LOADED_QTY','CLEARED_QTY','DELIVERED_QTY','FULLY_DELIVERED','FIRST_LOADED_AT','NET_UNIT_COST','AMOUNT','QUANTITY_BASE','UNIT_CONVERSION_SNAPSHOT'],
        'cement_loading' => ['QUANTITY_BASE','UNIT_CONVERSION_SNAPSHOT','CUSTOMER_QUANTITY','WAREHOUSE_QUANTITY','CLEARED_QUANTITY','CONFIRMED_AT','CONFIRMED_BY'],
        'customer_sales' => ['SOURCE_LOADING_ID','SOURCE_TRIP_ID','GROSS_AMOUNT','DISCOUNT_AMOUNT','TAX_AMOUNT','NET_AMOUNT','AMOUNT_PAID','BALANCE_DUE','DELIVERY_STATUS','DELIVERED_AT','OWNERSHIP_TRANSFER_POLICY'],
        'customer_sale_item' => ['SOURCE_LOADING_ID','SOURCE_PURCHASE_ITEM_ID','SOURCE_MOVEMENT_ID','QUANTITY_BASE','NET_UNIT_PRICE','LINE_AMOUNT','TAX_AMOUNT','LISTED_UNIT_PRICE','PRICE_LIST_ITEM_ID','UNIT_CONVERSION_SNAPSHOT'],
        'inventory_movement' => ['SOURCE_RECORD_TYPE_CODE','SOURCE_RECORD_ID','LOADING_ID','PURCHASE_ITEM_ID','SALE_ITEM_ID','TOTAL_COST','FIFO_ALLOCATIONS','EFFECTIVE_AT'],
        'inventory_stocktake_line' => ['SYSTEM_QTY','VARIANCE_QTY','VARIANCE_VALUE'],
        'trip_destination' => ['PROOF_ID','DELIVERY_STATUS','DELIVERED_AT'],'trip' => ['DESTINATION_COUNT','TOTAL_QUANTITY','TOTAL_FUEL_COST','TOTAL_MAINTENANCE_COST','TOTAL_TRIP_EXPENSE','TOTAL_TRIP_COST'],
        'supplier_incentive' => ['AMOUNT_RECEIVED'],
        'report_schedule' => ['LAST_RUN_AT','NEXT_RUN_AT'],'member_account' => ['BALANCE'],'account_balance' => ['BALANCE'],'bank_reconciliation' => ['RECONCILED_AT','STATEMENT_BALANCE','LEDGER_BALANCE','DIFFERENCE'],
        'fuel_transaction' => ['TOTAL_AMOUNT'], 'vehicle_maintenance' => ['TOTAL_COST'],
        'payroll_item' => ['GROSS_PAY','NET_PAY'], 'payroll' => ['TOTAL_GROSS','TOTAL_DEDUCTIONS','TOTAL_NET'],
    ];
    public const FINAL = ['paid','confirmed','verified','posted','invoiced','completed','received','partially_invoiced','reconciled','closed'];
    public function __construct(private Store $s)
    {
    }
    private function d(array $row): array
    {
        return $this->s->catalog->decode($row['type'], $row['data']);
    }
    private function sum(array $rows, string $alias): float
    {
        return array_sum(array_map(fn ($r) => (float)($this->d($r)[$alias] ?? 0), $rows));
    }
    private function positive(mixed $v, string $label = 'Quantity'): float
    {
        if (!is_numeric($v) || !is_finite((float)$v) || (float)$v <= 0) {
            throw new Problem($label.' must be greater than zero');
        }return (float)$v;
    }
    private function approval(array $actor): void
    {
        if (!in_array($actor['role'], ['cement_admin','cement_system_admin','cement_accountant'])) {
            throw new Problem('An administrator or accountant must approve this transition', 403);
        }
    }
    public function guard(array $actor, string $type, array $data, array $old, array $patch, string $op): void
    {
        if ($actor['role'] === 'cement_driver') {
            $scope = new Scope($this->s);
            if (!$scope->allows($actor, $type, $data)) {
                throw new Problem('This record is not assigned to your driver profile', 403);
            }
            if ($type === 'trip_destination' && ($op !== 'update' || array_diff(array_keys($patch), ['ARRIVAL_AT','NOTES','CODE','STATUS']))) {
                throw new Problem('Drivers can record arrival notes; delivery state changes through proof', 403);
            }
        }
        if ($old && in_array($old['STATUS'] ?? '', self::FINAL)) {
            throw new Problem('Finalized records are immutable; use a linked adjustment or return', 409);
        }
        foreach (array_merge(self::OWNED[$type] ?? [], in_array($type, ['journal_line','bank_statement_line']) ? [] : array_values(array_intersect(['JOURNAL_ID','POSTED_JOURNAL_ID'], array_keys($this->s->catalog->fields[$type] ?? [])))) as $alias) {
            if (array_key_exists($alias, $patch) && (!isset($old[$alias]) || $patch[$alias] !== $old[$alias])) {
                throw new Problem($alias.' is computed by the server');
            }
        }
        if ($type === 'purchaseItem' && ($old['LOADED_QTY'] ?? 0) > 0) {
            if ($op === 'delete') {
                throw new Problem('Loaded purchase items cannot be deleted');
            }
            foreach (['PRODUCT_ID','PURCHASE_ID','ORIGINAL_QUANTITY','UNIT_PRICE','UNIT_ID','DISCOUNT_TYPE','DISCOUNT_VALUE'] as $alias) {
                if (($data[$alias] ?? null) !== ($old[$alias] ?? null)) {
                    throw new Problem('Purchase item terms are locked after first loading');
                }
            }
        }
        $i = (int)$actor['institution_id'];
        foreach ((['purchaseItem' => ['PURCHASE_ID'],'customer_sale_item' => ['SALE_ID'],'payroll_item' => ['PAYROLL_ID','EMPLOYMENT_ID'],'trip_destination' => ['TRIP_ID'],'inventory_stocktake_line' => ['STOCKTAKE_ID'],'journal_line' => ['JOURNAL_ID'],'bank_statement_line' => ['STATEMENT_ID']][$type] ?? []) as $alias) {
            if ($old && ($data[$alias] ?? null) !== ($old[$alias] ?? null)) {
                throw new Problem('Parent references are immutable; recreate an unposted draft instead');
            }
        }
        if ($type === 'purchaseItem' && $op === 'delete' && $old && ($this->s->semantic($i, 'purchase', $old['PURCHASE_ID'])['PAID_AMOUNT'] ?? 0) > 0) {
            throw new Problem('Funded purchase items cannot be deleted; use an adjustment');
        }
        if ($type === 'customer_sales' && (!empty($old['SOURCE_LOADING_ID']) || ($old['AMOUNT_PAID'] ?? 0) > 0)) {
            foreach (['CUSTOMER_ID','SALE_DATE','CURRENCY'] as $alias) {
                if (($data[$alias] ?? null) !== ($old[$alias] ?? null)) {
                    throw new Problem('Loading-derived sale terms are immutable');
                }
            }
        }
        if ($type === 'customer_sale_item' && !empty($old['SOURCE_LOADING_ID'])) {
            if ($op === 'delete') {
                throw new Problem('Loading-derived sale lines cannot be deleted');
            }foreach (['PRODUCT_ID','UNIT_ID','QUANTITY','AGREED_UNIT_PRICE','DISCOUNT_TYPE','DISCOUNT_VALUE','TAX_RATE'] as $alias) {
                if (($data[$alias] ?? null) !== ($old[$alias] ?? null)) {
                    throw new Problem('Loading-derived sale snapshots are immutable');
                }
            }
        }
        if ($type === 'inventory_stocktake_line' && $op === 'delete' && $old && $this->s->semantic($i, 'inventory_stocktake', $old['STOCKTAKE_ID'])['STATUS'] === 'posted') {
            throw new Problem('Posted stocktake lines cannot be deleted');
        }
        $state = $data['STATUS'] ?? 'draft';
        $states = [
            'purchase' => ['draft','paid'],'purchaseItem' => ['draft'],'cement_loading' => ['draft','pending','confirmed'],
            'loading_clearance' => ['draft','verified'],'supplier_purchase_payment' => ['draft','completed'],
            'customer_sales' => ['draft','invoiced'],'customer_sale_item' => ['draft'],'customer_payment' => ['draft','completed'],
            'customer_sale_return' => ['draft','confirmed'],'delivery_proof' => ['draft','confirmed'],
            'inventory_movement' => ['draft','confirmed'],'inventory_stocktake' => ['draft','posted'],
            'stock_adjustment' => ['draft','confirmed'],'inventory_transfer' => ['draft','received'],'damaged_goods' => ['draft','confirmed'],'purchase_adjustment' => ['draft','posted'],
            'trip' => ['draft','pending','completed'],'fuel_transaction' => ['draft','posted'],
            'vehicle_maintenance' => ['draft','posted'],'trip_expense' => ['draft','posted'],
            'expense' => ['draft','posted'],'supplier_incentive' => ['draft','verified'],
            'supplier_incentive_receipt' => ['draft','completed'],'payroll' => ['draft','posted'],
            'payroll_payment' => ['draft','completed'],'payroll_item' => ['draft'],'journal' => ['draft','posted'],'journal_line' => ['draft'],'bank_reconciliation' => ['draft','confirmed'],'cooperative_transaction' => ['draft','posted'],'fiscal_year' => ['draft','active','closed'],
        ];
        if (!in_array($state, $states[$type] ?? ['draft','active','inactive','pending'], true)) {
            throw new Problem('Unsupported lifecycle state for '.$type);
        }
        if ($type === 'customer_sale_item' && $op === 'delete' && $old) {
            $parent = $this->s->semantic((int)$actor['institution_id'], 'customer_sales', $old['SALE_ID']);
            if (in_array($parent['STATUS'], self::FINAL) || ($parent['AMOUNT_PAID'] ?? 0) > 0) {
                throw new Problem('Invoiced or funded sale lines cannot be deleted');
            }
        }
        if ($type === 'journal_line' && $op === 'delete' && $old && $this->s->semantic((int)$actor['institution_id'], 'journal', $old['JOURNAL_ID'])['STATUS'] === 'posted') {
            throw new Problem('Posted journal lines cannot be deleted');
        }
        if ($type === 'bank_statement_line' && $op === 'delete' && $old && $this->s->semantic((int)$actor['institution_id'], 'bank_statement', $old['STATEMENT_ID'])['STATUS'] === 'reconciled') {
            throw new Problem('Reconciled bank lines cannot be deleted');
        }
        if ($type === 'payroll_item' && $op === 'delete' && $old && $this->s->semantic((int)$actor['institution_id'], 'payroll', $old['PAYROLL_ID'])['STATUS'] === 'posted') {
            throw new Problem('Posted payroll lines cannot be deleted');
        }
        if ($old && ($old['STATUS'] ?? 'draft') !== $state && !in_array($state, ['draft','active','inactive','pending',...self::FINAL])) {
            throw new Problem('Unsupported lifecycle transition');
        }
        if (in_array($state, self::FINAL)) {
            $supported = ['purchase','cement_loading','loading_clearance','supplier_purchase_payment','customer_sales','customer_payment','customer_sale_return','delivery_proof','inventory_movement','inventory_stocktake','stock_adjustment','inventory_transfer','trip','fuel_transaction','vehicle_maintenance','trip_expense','supplier_incentive','supplier_incentive_receipt','payroll','payroll_payment','expense','journal','bank_reconciliation','cooperative_transaction','damaged_goods','purchase_adjustment','fiscal_year'];
            if (!in_array($type, $supported)) {
                throw new Problem('Use the parent business workflow to finalize this record');
            }
            $policy = $this->s->settings($i);
            $approvalTypes = ['purchase','fuel_transaction','vehicle_maintenance','trip_expense','inventory_stocktake','payroll','expense','purchase_adjustment','customer_sale_return','inventory_transfer','damaged_goods'];
            if ($type === 'cement_loading' && $policy['requireLoadingApproval']) {
                $approvalTypes[] = 'cement_loading';
            }
            if ($type === 'stock_adjustment' && $policy['requireStockAdjustmentApproval']) {
                $approvalTypes[] = 'stock_adjustment';
            }
            if (in_array($type, $approvalTypes)) {
                $this->approval($actor);
            }
            if ($type === 'customer_sales' && !in_array($actor['role'], ['cement_system_admin','cement_admin','cement_sales_manager'])) {
                throw new Problem('Sales approval is required', 403);
            }
        }


    }
    public function prepare(array $actor, string $type, array $d, array $old): array
    {
        $i = (int)$actor['institution_id'];
        $settings = $this->s->settings($i);
        $d['STATUS'] ??= 'draft';
        if (isset($this->s->catalog->fields[$type]['CURRENCY'])) {
            $d['CURRENCY'] ??= $settings['defaultCurrency'];
            if ($d['CURRENCY'] !== $settings['defaultCurrency']) {
                throw new Problem('Local posting uses the institution’s base currency; foreign-currency conversion requires an explicit adapter');
            }
        }
        switch ($type) {
            case 'report_schedule':
                if (!in_array($d['CADENCE'] ?? '', ['daily','weekly','monthly'])) {
                    throw new Problem('Schedule cadence must be daily, weekly or monthly');
                }
                try {
                    new \DateTimeZone($d['TIMEZONE'] ?? '');
                } catch (\Throwable) {
                    throw new Problem('Invalid schedule timezone');
                }
                foreach ($d['RECIPIENTS'] ?? [] as $recipient) {
                    if (!is_string($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                        throw new Problem('Schedule recipients must be email addresses');
                    }
                }
                if (!in_array($d['OUTPUT_FORMAT'] ?? 'csv', ['csv','xlsx'])) {
                    throw new Problem('Scheduled formats are CSV or XLSX');
                }break;
            case 'purchase':
                $d += ['GROSS_AMOUNT' => 0,'DISCOUNT_AMOUNT' => 0,'NET_AMOUNT' => 0,'PAID_AMOUNT' => 0,'CURRENCY' => 'NGN'];
                $d['OWNERSHIP_TRANSFER_POLICY'] = $settings['ownershipTransferPolicy'];
                break;
            case 'purchaseItem':
                $parent = $this->s->semantic($i, 'purchase', (int)($d['PURCHASE_ID'] ?? 0));
                if (in_array($parent['STATUS'], self::FINAL) && !$old) {
                    throw new Problem('Cannot add items after purchase funding');
                }
                if (($parent['PAID_AMOUNT'] ?? 0) > 0 && $old) {
                    foreach (['PRODUCT_ID','PURCHASE_ID','ORIGINAL_QUANTITY','UNIT_PRICE','UNIT_ID','DISCOUNT_TYPE','DISCOUNT_VALUE'] as $alias) {
                        if (($d[$alias] ?? null) !== ($old[$alias] ?? null)) {
                            throw new Problem('Funded purchase terms require a linked adjustment');
                        }
                    }
                }
                $qty = $this->positive($d['ORIGINAL_QUANTITY'] ?? 0);
                $price = $this->positive($d['UNIT_PRICE'] ?? 0, 'Unit price');
                $discount = (float)($d['DISCOUNT_VALUE'] ?? 0);
                if ($discount < 0 || (($d['DISCOUNT_TYPE'] ?? '') === 'percent' && $discount > 100)) {
                    throw new Problem('Invalid discount');
                }
                $cost = ($d['DISCOUNT_TYPE'] ?? '') === 'percent' ? $price * (1 - $discount / 100) : $price - $discount;
                if ($cost < 0) {
                    throw new Problem('Discount exceeds price');
                }
                $conversion = $this->conversion($i, (int)$d['PRODUCT_ID'], (int)$d['UNIT_ID']);
                $cost = round($cost, 2);
                $d = array_replace($d, ['NET_UNIT_COST' => round($cost, 2),'AMOUNT' => round($qty * $cost, 2),'QUANTITY_BASE' => $qty * $conversion,'UNIT_CONVERSION_SNAPSHOT' => ['factor' => $conversion]]);
                $d += ['LOADED_QTY' => 0,'CLEARED_QTY' => 0,'DELIVERED_QTY' => 0,'FULLY_DELIVERED' => false];
                break;
            case 'cement_loading':
                $item = $this->s->semantic($i, 'purchaseItem', (int)($d['PURCHASE_ITEM_ID'] ?? 0));
                $qty = $this->positive($d['QUANTITY'] ?? 0);
                $d['UNIT_ID'] = $item['UNIT_ID'];
                $d['QUANTITY_BASE'] = $qty * $item['UNIT_CONVERSION_SNAPSHOT']['factor'];
                $d['UNIT_CONVERSION_SNAPSHOT'] = $item['UNIT_CONVERSION_SNAPSHOT'];
                $d += ['CLEARED_QUANTITY' => 0];
                $customer = 0;
                $warehouse = 0;
                foreach ($d['ALLOCATION_ROWS'] ?? [] as $row) {
                    if (!is_array($row)) {
                        throw new Problem('Allocation must be an object');
                    }
                    $allowed = [$this->s->catalog->fields['cement_loading']['QUANTITY'],$this->s->catalog->fields['customer_sales']['CUSTOMER_ID'],$this->s->catalog->fields['customer_sales']['WAREHOUSE_ID'],$this->s->catalog->fields['customer_sale_item']['AGREED_UNIT_PRICE']];
                    if (array_diff(array_keys($row), $allowed)) {
                        throw new Problem('Unknown nested allocation field');
                    }
                    // Nested business keys use the same typed field registry as root records.
                    $q = $this->positive($row[$this->s->catalog->fields['cement_loading']['QUANTITY']] ?? 0);
                    $cid = $row[$this->s->catalog->fields['customer_sales']['CUSTOMER_ID']] ?? null;
                    $wid = $row[$this->s->catalog->fields['customer_sales']['WAREHOUSE_ID']] ?? null;
                    if ((bool)$cid === (bool)$wid) {
                        throw new Problem('Each allocation must target exactly one customer or warehouse');
                    }
                    $this->s->get($i, $cid ? 'customer' : 'inventory_location', (int)($cid ?: $wid));
                    if ($cid) {
                        $customer += $q;
                    } else {
                        $warehouse += $q;
                    }
                }
                $d['CUSTOMER_QUANTITY'] = $customer;
                $d['WAREHOUSE_QUANTITY'] = $warehouse;
                if (in_array($d['STATUS'], self::FINAL)) {
                    if (abs($customer + $warehouse - $qty) > 0.000001) {
                        throw new Problem('Customer and warehouse allocations must equal loading quantity');
                    }
                    if (($item['LOADED_QTY'] ?? 0) + $qty > $this->entitlement($i, $d['PURCHASE_ITEM_ID']) + 0.000001) {
                        throw new Problem('Loading exceeds remaining purchase entitlement');
                    }
                    $purchase = $this->s->semantic($i, 'purchase', $item['PURCHASE_ID']);
                    if (($purchase['PAID_AMOUNT'] ?? 0) < $purchase['NET_AMOUNT'] || $purchase['NET_AMOUNT'] <= 0) {
                        throw new Problem('Fund this purchase before confirming loading');
                    }
                    $depot = $this->s->semantic($i, 'supplier_depot', (int)($d['SUPPLIER_DEPOT_ID'] ?? 0));
                    if ($depot['SUPPLIER_ID'] !== $purchase['SUPPLIER_ID']) {
                        throw new Problem('Depot belongs to another supplier');
                    }
                    $d['CONFIRMED_AT'] = gmdate('c');
                    $d['STATUS'] = 'confirmed';
                } break;
            case 'purchase_adjustment':
                if (empty($d['REASON'])) {
                    throw new Problem('An adjustment needs an audit reason');
                }
                if (!empty($d['PURCHASE_ITEM_ID'])) {
                    $item = $this->s->semantic($i, 'purchaseItem', $d['PURCHASE_ITEM_ID']);
                    if ($item['PURCHASE_ID'] !== $d['PURCHASE_ID']) {
                        throw new Problem('Adjustment item belongs to another purchase');
                    }
                    $delta = $d['QUANTITY'] ?? 0;
                    $prior = $this->entitlement($i, $d['PURCHASE_ITEM_ID']);
                    if ($prior + $delta < $item['LOADED_QTY'] || $prior + $delta < 0) {
                        throw new Problem('Adjustment would reduce entitlement below loaded quantity');
                    }
                    if ($delta) {
                        $amount = round($delta * $item['NET_UNIT_COST'], 2);
                        if (isset($d['AMOUNT']) && abs($amount - $d['AMOUNT']) > 0.005) {
                            throw new Problem('Quantity adjustment amount must use the item cost snapshot');
                        }$d['AMOUNT'] = $amount;
                    }
                } elseif (!empty($d['QUANTITY'])) {
                    throw new Problem('Quantity adjustments require a purchase item');
                }
                if (!($d['AMOUNT'] ?? 0) && !($d['QUANTITY'] ?? 0)) {
                    throw new Problem('Adjustment must change value or quantity');
                }break;
            case 'customer_sales':
                $d += ['GROSS_AMOUNT' => 0,'DISCOUNT_AMOUNT' => 0,'TAX_AMOUNT' => 0,'NET_AMOUNT' => 0,'AMOUNT_PAID' => 0,'BALANCE_DUE' => 0,'DELIVERY_STATUS' => 'pending','CURRENCY' => 'NGN'];
                $d['OWNERSHIP_TRANSFER_POLICY'] = $settings['ownershipTransferPolicy'];
                break;
            case 'customer_sale_item':
                $sale = $this->s->semantic($i, 'customer_sales', (int)($d['SALE_ID'] ?? 0));
                if (in_array($sale['STATUS'], self::FINAL) || ($sale['AMOUNT_PAID'] ?? 0) > 0) {
                    throw new Problem('Invoiced or funded sale lines are immutable');
                }
                $qty = $this->positive($d['QUANTITY'] ?? 0);
                $price = $this->resolvePrice($i, $sale, $d);
                $agreed = $d['AGREED_UNIT_PRICE'] ?? $price['value'];
                if ($agreed === null) {
                    throw new Problem('No effective price tier; add a dated price list entry');
                }
                if ($price['value'] === null && !$settings['allowSalesPriceOverride']) {
                    throw new Problem('No effective price tier; add a dated price list entry');
                }
                if (!$settings['allowSalesPriceOverride'] && $price['value'] !== null && abs($agreed - $price['value']) > 0.005) {
                    throw new Problem('Sales price override is disabled');
                }
                if ($agreed < 0) {
                    throw new Problem('Invalid agreed price');
                }
                $discount = (float)($d['DISCOUNT_VALUE'] ?? 0);
                $net = ($d['DISCOUNT_TYPE'] ?? '') === 'percent' ? $agreed * (1 - $discount / 100) : $agreed - $discount;
                $tax = (float)($d['TAX_RATE'] ?? 0);
                if ($net < 0 || $tax < 0 || $tax > 100 || $discount < 0) {
                    throw new Problem('Invalid discount or tax');
                }
                $net = round($net, 2);
                $factor = $this->conversion($i, (int)$d['PRODUCT_ID'], (int)$d['UNIT_ID']);
                $d = array_replace($d, ['AGREED_UNIT_PRICE' => $agreed,'NET_UNIT_PRICE' => round($net, 2),'TAX_AMOUNT' => round($net * $qty * $tax / 100, 2),'LINE_AMOUNT' => round($net * $qty * (1 + $tax / 100), 2),'QUANTITY_BASE' => $qty * $factor,'UNIT_CONVERSION_SNAPSHOT' => ['factor' => $factor]]);
                if ($price['id']) {
                    $d['PRICE_LIST_ITEM_ID'] = $price['id'];
                }if ($price['value'] !== null) {
                    $d['LISTED_UNIT_PRICE'] = $price['value'];
                }break;
            case 'inventory_movement':
                if (in_array($d['STATUS'], self::FINAL) && strtotime($d['MOVEMENT_DATE'] ?? 'now') > time()) {
                    throw new Problem('A future movement must remain pending');
                }
                $this->positive($d['QTY_BASE'] ?? 0);
                $d['COST_POLICY'] = $settings['inventoryValuationMethod'];
                if (!empty($d['SOURCE_LOCATION_ID']) && !empty($d['DESTINATION_LOCATION_ID']) && $d['SOURCE_LOCATION_ID'] === $d['DESTINATION_LOCATION_ID']) {
                    throw new Problem('Source and destination locations must differ');
                }
                if (empty($d['SOURCE_LOCATION_ID']) && empty($d['DESTINATION_LOCATION_ID'])) {
                    throw new Problem('Movement requires a source or destination');
                }
                if (isset($d['EFFECTIVE_AT']) && strtotime($d['EFFECTIVE_AT']) > time()) {
                    throw new Problem('Future stock effects must stay pending');
                }break;
            case 'trip_destination':
                $trip = $this->s->semantic($i, 'trip', (int)($d['TRIP_ID'] ?? 0));
                if ($trip['STATUS'] === 'completed') {
                    throw new Problem('Completed trip destinations are immutable');
                }
                foreach ($this->s->related($i, 'trip_destination', 'TRIP_ID', $d['TRIP_ID']) as $row) {
                    $other = $this->d($row);
                    if (($other['CODE'] ?? null) !== ($d['CODE'] ?? null) && $other['DESTINATION_ORDER'] === ($d['DESTINATION_ORDER'] ?? null)) {
                        throw new Problem('Destination order must be unique within this trip');
                    }
                }
                if (!empty($d['SALE_ID'])) {
                    $sale = $this->s->semantic($i, 'customer_sales', $d['SALE_ID']);
                    if (!empty($d['CUSTOMER_ID']) && $d['CUSTOMER_ID'] !== $sale['CUSTOMER_ID']) {
                        throw new Problem('Destination customer differs from its sale');
                    }$d['CUSTOMER_ID'] = $sale['CUSTOMER_ID'];
                }
                $d += ['DELIVERY_STATUS' => 'pending'];
                break;
            case 'inventory_stocktake_line':
                $take = $this->s->semantic($i, 'inventory_stocktake', (int)($d['STOCKTAKE_ID'] ?? 0));
                if ($take['STATUS'] === 'posted') {
                    throw new Problem('Posted count cannot be changed');
                }
                $stock = $this->stock($i, (int)$take['LOCATION_ID'], (int)$d['PRODUCT_ID'], $d['STOCK_CONDITION']);
                if (($d['COUNTED_QTY'] ?? -1) < 0) {
                    throw new Problem('Count cannot be negative');
                }
                $d['SYSTEM_QTY'] = $stock['QUANTITY_ON_HAND'];
                $d['VARIANCE_QTY'] = $d['COUNTED_QTY'] - $d['SYSTEM_QTY'];
                $d['UNIT_COST'] = $stock['AVERAGE_UNIT_COST'];
                $d['VARIANCE_VALUE'] = round($d['VARIANCE_QTY'] * $d['UNIT_COST'], 2);
                break;
            case 'fuel_transaction': $d['TOTAL_AMOUNT'] = round($this->positive($d['LITRES'] ?? 0) * $this->positive($d['PRICE_PER_LITRE'] ?? 0), 2);
                break;
            case 'vehicle_maintenance': $d['TOTAL_COST'] = round(($d['PARTS_COST'] ?? 0) + ($d['LABOR_COST'] ?? 0), 2);
                break;
            case 'supplier_incentive':
                $d += ['AMOUNT_RECEIVED' => 0];
                if (!empty($d['SCHEME_ID'])) {
                    if (!$settings['enableIncentiveSchemes']) {
                        throw new Problem('Incentive schemes are disabled by institution policy');
                    }
                    $scheme = $this->s->semantic($i, 'supplier_incentive_scheme', $d['SCHEME_ID']);
                    if ($scheme['SUPPLIER_ID'] !== $d['SUPPLIER_ID'] || $scheme['STATUS'] !== 'active' || ($scheme['VALID_FROM'] ?? '') > ($d['BENEFIT_DATE'] ?? '') || (!empty($scheme['VALID_TO']) && $scheme['VALID_TO'] < $d['BENEFIT_DATE'])) {
                        throw new Problem('Scheme is not effective for this supplier and date');
                    }
                    $q = $d['BASIS_QUANTITY'] ?? 0;
                    $a = $d['BASIS_AMOUNT'] ?? 0;
                    if ($q < ($scheme['THRESHOLD_QTY'] ?? 0) || $a < ($scheme['THRESHOLD_AMOUNT'] ?? 0)) {
                        throw new Problem('Scheme threshold has not been met');
                    }
                    $d['ACTUAL_AMOUNT'] = round(match($scheme['CALCULATION_TYPE']) {
                        'percent' => $a * ($scheme['RATE'] ?? 0) / 100,'per_quantity' => $q * ($scheme['RATE'] ?? 0),'fixed' => $scheme['FIXED_AMOUNT'] ?? 0,default => throw new Problem('Unsupported incentive calculation')
                    }, 2);
                }break;
            case 'payroll_item':
                $payroll = $this->s->semantic($i, 'payroll', (int)($d['PAYROLL_ID'] ?? 0));
                if ($payroll['STATUS'] === 'posted') {
                    throw new Problem('Posted payroll items are immutable');
                }

                $employment = $this->s->semantic($i, 'staff_employment', (int)($d['EMPLOYMENT_ID'] ?? 0));
                foreach ($this->s->related($i, 'payroll_item', 'EMPLOYMENT_ID', $d['EMPLOYMENT_ID']) as $r) {
                    $existing = $this->d($r);
                    if (($existing['CODE'] ?? null) === ($old['CODE'] ?? $d['CODE'] ?? null)) {
                        continue;
                    }$other = $this->s->semantic($i, 'payroll', $existing['PAYROLL_ID']);
                    if ($payroll['PERIOD_START'] <= $other['PERIOD_END'] && $payroll['PERIOD_END'] >= $other['PERIOD_START']) {
                        throw new Problem('This employee already has payroll in an overlapping period');
                    }
                }
                if (!$old) {
                    $scheduled = [];
                    foreach ($this->s->related($i, 'staff_deduction', 'EMPLOYMENT_ID', $d['EMPLOYMENT_ID']) as $r) {
                        $schedule = $this->d($r);
                        if ($schedule['STATUS'] !== 'active' || $schedule['START_DATE'] > $payroll['PERIOD_END'] || (!empty($schedule['END_DATE']) && $schedule['END_DATE'] < $payroll['PERIOD_START'])) {
                            continue;
                        }
                        $kind = $schedule['DEDUCTION_TYPE'];
                        $alias = match($kind) {
                            'savings' => 'SAVINGS_DEDUCTION','loan' => 'LOAN_DEDUCTION','tax' => 'TAX_AMOUNT','pension' => 'PENSION_AMOUNT',default => 'DEDUCTIONS'
                        };
                        $amount = round(($schedule['FIXED_AMOUNT'] ?? 0) + ($schedule['RATE'] ?? 0) * ($d['BASIC_SALARY'] ?? 0) / 100, 2);
                        if (in_array($kind, ['savings','loan'])) {
                            $link = $kind === 'savings' ? 'DEFAULT_SAVINGS_ACCOUNT_ID' : 'DEFAULT_LOAN_ACCOUNT_ID';
                            $target = $kind === 'savings' ? 'SAVINGS_ACCOUNT_ID' : 'LOAN_ACCOUNT_ID';
                            if (!empty($schedule[$target]) && ($employment[$link] ?? null) !== $schedule[$target]) {
                                throw new Problem('Employment default Cooperative account differs from deduction schedule');
                            }
                        }
                        if ($alias === 'DEDUCTIONS') {
                            $scheduled[$alias][] = $amount;
                        } else {
                            $scheduled[$alias] = ($scheduled[$alias] ?? 0) + $amount;
                        }
                    }foreach ($scheduled as $alias => $amount) {
                        if (!array_key_exists($alias, $d)) {
                            $d[$alias] = $amount;
                        }
                    }
                }
                foreach (['BASIC_SALARY','OTHER_EARNINGS','TAX_AMOUNT','PENSION_AMOUNT','SAVINGS_DEDUCTION','LOAN_DEDUCTION'] as $alias) {
                    if (($d[$alias] ?? 0) < 0) {
                        throw new Problem('Payroll earnings and deductions must be nonnegative');
                    }
                }
                $allowances = array_sum(array_map(fn ($v) => is_numeric($v) ? (float)$v : throw new Problem('Allowances must be a list of amounts'), $d['ALLOWANCES'] ?? []));
                $deductions = array_sum(array_map(fn ($v) => is_numeric($v) ? (float)$v : throw new Problem('Deductions must be a list of amounts'), $d['DEDUCTIONS'] ?? []));
                $d['GROSS_PAY'] = round(($d['BASIC_SALARY'] ?? 0) + $allowances + ($d['OTHER_EARNINGS'] ?? 0), 2);
                $d['NET_PAY'] = round($d['GROSS_PAY'] - ($d['TAX_AMOUNT'] ?? 0) - ($d['PENSION_AMOUNT'] ?? 0) - $deductions - ($d['SAVINGS_DEDUCTION'] ?? 0) - ($d['LOAN_DEDUCTION'] ?? 0), 2);
                if ($d['NET_PAY'] < 0) {
                    throw new Problem('Payroll deductions exceed earnings');
                }break;
        }
        return (new SharedServices($this->s))->prepare($actor, $type, $d, $old);
    }
    private function conversion(int $i, int $product, int $unit): float
    {
        $p = $this->s->semantic($i, 'product', $product);
        $u = $this->s->semantic($i, 'unit_of_measure', $unit);
        if ($p['BASE_UNIT_ID'] === $unit) {
            return 1;
        }
        if (($p['PACKAGE_UNIT_ID'] ?? null) === $unit && ($p['PACKAGE_SIZE'] ?? 0) > 0) {
            return (float)$p['PACKAGE_SIZE'];
        }
        throw new Problem('Declare this product’s unit conversion before using that unit');
    }
    private function resolvePrice(int $i, array $sale, array $item): array
    {
        $date = substr($sale['SALE_DATE'], 0, 10);
        $matches = [];
        foreach ($this->s->all($i, 'price_list_item') as $r) {
            $d = $this->d($r);
            $list = $this->s->semantic($i, 'price_list', $d['PRICE_LIST_ID']);
            if ($d['PRODUCT_ID'] !== $item['PRODUCT_ID'] || $d['UNIT_ID'] !== $item['UNIT_ID'] || $d['MIN_QUANTITY'] > $item['QUANTITY']) {
                continue;
            }
            if (($d['MAX_QUANTITY'] ?? 0) > 0 && $d['MAX_QUANTITY'] < $item['QUANTITY']) {
                continue;
            }
            if (($list['STATUS'] ?? '') !== 'active' || ($d['STATUS'] ?? '') !== 'active') {
                continue;
            }
            if (($list['VALID_FROM'] ?? '') > $date || (!empty($list['VALID_TO']) && $list['VALID_TO'] < $date)) {
                continue;
            }
            if (($d['VALID_FROM'] ?? '') > $date || (!empty($d['VALID_TO']) && $d['VALID_TO'] < $date)) {
                continue;
            }
            $customer = $d['CUSTOMER_ID'] ?? $list['CUSTOMER_ID'] ?? null;
            if ($customer && $customer !== $sale['CUSTOMER_ID']) {
                continue;
            }
            $matches[] = ['id' => $r['id'],'value' => $d['UNIT_PRICE'],'priority' => [$customer ? 1 : 0,$d['PRIORITY'] ?? $list['PRIORITY'] ?? 0,$d['MIN_QUANTITY']]];
        }
        usort($matches, fn ($a, $b) => $b['priority'] <=> $a['priority']);
        return $matches[0] ?? ['id' => null,'value' => null];
    }
    public function after(array $actor, string $type, int $id, array $d, array $old): void
    {
        $i = (int)$actor['institution_id'];
        $final = in_array($d['STATUS'] ?? '', self::FINAL);
        if ($type === 'purchaseItem') {
            $this->rollupPurchase($i, $d['PURCHASE_ID']);
            return;
        }
        if ($type === 'customer_sale_item') {
            $this->rollupSale($i, $d['SALE_ID']);
            return;
        }
        if ($type === 'payroll_item') {
            $this->rollupPayroll($i, $d['PAYROLL_ID']);
            return;
        }
        if ($type === 'trip_destination') {
            $stops = $this->s->related($i, 'trip_destination', 'TRIP_ID', $d['TRIP_ID']);
            $quantity = $this->sum($stops, 'QUANTITY');
            $trip = $this->s->semantic($i, 'trip', $d['TRIP_ID']);
            if (!empty($trip['LOADING_ID']) && $quantity > $this->s->semantic($i, 'cement_loading', $trip['LOADING_ID'])['QUANTITY'] + 0.000001) {
                throw new Problem('Trip destinations exceed its loading quantity');
            }
            $this->s->patch($i, 'trip', $d['TRIP_ID'], ['DESTINATION_COUNT' => count($stops),'TOTAL_QUANTITY' => $quantity]);
            if (!empty($d['SALE_ID'])) {
                $this->s->patch($i, 'customer_sales', $d['SALE_ID'], ['SOURCE_TRIP_ID' => $d['TRIP_ID']]);
            }
        }
        if (!$final) {
            return;
        }
        (new SharedServices($this->s))->after($actor, $type, $id, $d);
        $source = $type.':'.$id;
        switch ($type) {
            case 'purchase':
                $this->rollupPurchase($i, $id);
                $purchase = $this->s->semantic($i, 'purchase', $id);
                $amount = $purchase['NET_AMOUNT'] - $purchase['PAID_AMOUNT'];
                if ($purchase['NET_AMOUNT'] <= 0) {
                    throw new Problem('Purchase needs priced items');
                }
                $this->s->journal($i, $source, $this->prepaidCode($i, $d), $this->paymentCode($i, $d), $amount);
                $this->s->patch($i, 'purchase', $id, ['STATUS' => 'paid','PAID_AMOUNT' => $purchase['NET_AMOUNT']]);
                break;
            case 'purchase_adjustment':
                $purchase = $this->s->semantic($i, 'purchase', $d['PURCHASE_ID']);
                $delta = $d['AMOUNT'] ?? 0;
                if ($purchase['PAID_AMOUNT'] + $delta < 0) {
                    throw new Problem('Adjustment exceeds funded purchase value');
                }
                $this->s->journal($i, $source, $delta > 0 ? 'supplier_prepaid' : 'bank', $delta > 0 ? 'bank' : 'supplier_prepaid', abs($delta));
                $this->rollupPurchase($i, $d['PURCHASE_ID']);
                $this->s->patch($i, 'purchase', $d['PURCHASE_ID'], ['PAID_AMOUNT' => round($purchase['PAID_AMOUNT'] + $delta, 2)]);
                break;
            case 'supplier_purchase_payment':
                $p = $this->s->semantic($i, 'purchase', $d['PURCHASE_ID']);
                $amount = $this->positive($d['AMOUNT'], 'Payment');
                if ($amount > $p['NET_AMOUNT'] - $p['PAID_AMOUNT'] + 0.001) {
                    throw new Problem('Payment exceeds outstanding purchase value');
                }
                $this->s->journal($i, $source, $this->prepaidCode($i, $d), $this->paymentCode($i, $d), $amount);
                $this->s->patch($i, 'purchase', $d['PURCHASE_ID'], ['PAID_AMOUNT' => $p['PAID_AMOUNT'] + $amount,'STATUS' => $p['PAID_AMOUNT'] + $amount >= $p['NET_AMOUNT'] ? 'paid' : 'draft']);
                break;
            case 'cement_loading': $this->loading($actor, $id, $d);
                break;
            case 'loading_clearance':
                $item = $this->s->semantic($i, 'purchaseItem', $d['PURCHASE_ITEM_ID']);
                $qty = $this->positive($d['QUANTITY']);
                if ($qty + $item['CLEARED_QTY'] > $item['LOADED_QTY'] + 0.000001) {
                    throw new Problem('Clearance exceeds loaded quantity');
                }
                if (!empty($d['LOADING_ID'])) {
                    $load = $this->s->semantic($i, 'cement_loading', $d['LOADING_ID']);
                    if ($load['PURCHASE_ITEM_ID'] !== $d['PURCHASE_ITEM_ID'] || $load['STATUS'] !== 'confirmed') {
                        throw new Problem('Clearance must use a confirmed loading for this item');
                    }
                    if ($load['CLEARED_QUANTITY'] + $qty > $load['QUANTITY'] + 0.000001) {
                        throw new Problem('Clearance exceeds this loading');
                    }
                    $this->s->patch($i, 'cement_loading', $d['LOADING_ID'], ['CLEARED_QUANTITY' => $load['CLEARED_QUANTITY'] + $qty]);
                }
                if ($this->s->settings($i)['loadingClearingMode'] === 'full_only' && abs($qty - ($item['LOADED_QTY'] - $item['CLEARED_QTY'])) > 0.000001) {
                    throw new Problem('Institution requires full clearance');
                }
                $this->s->patch($i, 'purchaseItem', $d['PURCHASE_ITEM_ID'], ['CLEARED_QTY' => $item['CLEARED_QTY'] + $qty]);
                break;
            case 'customer_sales':
                if ($d['OWNERSHIP_TRANSFER_POLICY'] === 'delivery-transfer') {
                    throw new Problem('Revenue for this order transfers through confirmed delivery proof, including partial acceptance');
                }
                $this->invoice($i, $id, $d);
                break;
            case 'customer_payment':
                if (empty($d['SALE_ID'])) {
                    $this->s->journal($i, $source, $this->paymentCode($i, $d), 'customer_deposits', $this->positive($d['AMOUNT'], 'Deposit'));
                    break;
                }
                $sale = $this->s->semantic($i, 'customer_sales', $d['SALE_ID']);
                $amount = $this->positive($d['AMOUNT'], 'Receipt');
                if ($sale['CUSTOMER_ID'] !== $d['CUSTOMER_ID'] || !in_array($sale['STATUS'], ['draft','invoiced','partially_invoiced'])) {
                    throw new Problem('Receipt must match this customer’s order or invoice');
                }
                $due = $sale['STATUS'] === 'invoiced' ? $sale['BALANCE_DUE'] : $sale['NET_AMOUNT'] - $sale['AMOUNT_PAID'];
                if ($amount > $due + 0.001) {
                    throw new Problem('Receipt exceeds the order or invoice balance');
                }
                $againstReceivable = $sale['STATUS'] === 'draft' ? 0 : min($amount, $sale['BALANCE_DUE']);
                $this->s->journal($i, $source.':receivable', $this->paymentCode($i, $d), 'receivables', $againstReceivable);
                $this->s->journal($i, $source.':deposit', $this->paymentCode($i, $d), 'customer_deposits', $amount - $againstReceivable);
                $this->s->patch($i, 'customer_sales', $d['SALE_ID'], ['AMOUNT_PAID' => $sale['AMOUNT_PAID'] + $amount,'BALANCE_DUE' => $sale['STATUS'] === 'draft' ? round($due - $amount, 2) : max(0, round($sale['BALANCE_DUE'] - $againstReceivable, 2))]);
                break;
            case 'inventory_movement':$this->movement($i, $id, $d);
                break;
            case 'damaged_goods':
                $qty = $this->positive($d['QUANTITY']);
                $decision = $d['DECISION'] ?? '';
                if (!in_array($decision, ['quarantine','write_off'])) {
                    throw new Problem('Confirm a quarantine or write_off decision');
                }
                $move = $this->createMovement($i, ['PRODUCT_ID' => $d['PRODUCT_ID'],'SOURCE_LOCATION_ID' => $d['LOCATION_ID'],'STOCK_CONDITION' => $d['CONDITION'],'QTY_BASE' => $qty,'SOURCE_RECORD_TYPE_CODE' => $type,'SOURCE_RECORD_ID' => $id,'MOVEMENT_TYPE' => $decision]);
                $cost = $this->s->semantic($i, 'inventory_movement', $move)['UNIT_COST'];
                if ($decision === 'quarantine') {
                    $this->createMovement($i, ['PRODUCT_ID' => $d['PRODUCT_ID'],'DESTINATION_LOCATION_ID' => $d['LOCATION_ID'],'STOCK_CONDITION' => 'damaged','QTY_BASE' => $qty,'UNIT_COST' => $cost,'SOURCE_RECORD_TYPE_CODE' => $type,'SOURCE_RECORD_ID' => $id]);
                }
                $this->s->patch($i, 'damaged_goods', $id, ['RELATED_MOVEMENT_ID' => $move]);
                break;
            case 'stock_adjustment':
                $delta = $d['QUANTITY_DELTA'];
                if (!$delta) {
                    throw new Problem('Adjustment must change stock');
                }
                $mov = $this->createMovement($i, ['PRODUCT_ID' => $d['PRODUCT_ID'],'STOCK_CONDITION' => $d['STOCK_CONDITION'],'QTY_BASE' => abs($delta),'UNIT_COST' => $d['UNIT_COST'] ?? 0,($delta > 0 ? 'DESTINATION_LOCATION_ID' : 'SOURCE_LOCATION_ID') => $d['LOCATION_ID'],'SOURCE_RECORD_TYPE_CODE' => $type,'SOURCE_RECORD_ID' => $id]);
                $this->s->patch($i, $type, $id, ['SOURCE_MOVEMENT_ID' => $mov]);
                break;
            case 'inventory_transfer':
                if ($d['SOURCE_LOCATION_ID'] === $d['DESTINATION_LOCATION_ID']) {
                    throw new Problem('Transfer locations must differ');
                }
                $this->createMovement($i, ['PRODUCT_ID' => $d['PRODUCT_ID'],'STOCK_CONDITION' => 'good','QTY_BASE' => $this->positive($d['QUANTITY']) * $this->conversion($i, $d['PRODUCT_ID'], $d['UNIT_ID']),'SOURCE_LOCATION_ID' => $d['SOURCE_LOCATION_ID'],'DESTINATION_LOCATION_ID' => $d['DESTINATION_LOCATION_ID'],'SOURCE_RECORD_TYPE_CODE' => $type,'SOURCE_RECORD_ID' => $id]);
                break;
            case 'inventory_stocktake':
                $lines = $this->s->related($i, 'inventory_stocktake_line', 'STOCKTAKE_ID', $id);
                if (!$lines) {
                    throw new Problem('Add count lines before posting');
                }
                foreach ($lines as $row) {
                    $line = $this->d($row);
                    $stock = $this->stock($i, $d['LOCATION_ID'], $line['PRODUCT_ID'], $line['STOCK_CONDITION']);
                    if (abs($stock['QUANTITY_ON_HAND'] - $line['SYSTEM_QTY']) > 0.000001) {
                        throw new Problem('Stock changed after count; recount before posting');
                    }
                    $delta = $line['VARIANCE_QTY'];
                    if (!$delta) {
                        continue;
                    }
                    $adjustment = $this->s->save($i, 'stock_adjustment', ['LOCATION_ID' => $d['LOCATION_ID'],'PRODUCT_ID' => $line['PRODUCT_ID'],'ADJUSTMENT_DATE' => gmdate('Y-m-d'),'REASON_CODE' => 'stocktake_variance','STOCK_CONDITION' => $line['STOCK_CONDITION'],'QUANTITY_DELTA' => $delta,'UNIT_COST' => $line['UNIT_COST'],'AMOUNT' => $line['VARIANCE_VALUE'],'STOCKTAKE_LINE_ID' => $row['id'],'STATUS' => 'confirmed']);
                    $move = $this->createMovement($i, ['PRODUCT_ID' => $line['PRODUCT_ID'],'STOCK_CONDITION' => $line['STOCK_CONDITION'],'QTY_BASE' => abs($delta),'UNIT_COST' => $line['UNIT_COST'],($delta > 0 ? 'DESTINATION_LOCATION_ID' : 'SOURCE_LOCATION_ID') => $d['LOCATION_ID'],'SOURCE_RECORD_TYPE_CODE' => 'stock_adjustment','SOURCE_RECORD_ID' => $adjustment['id']]);
                    $this->s->patch($i, 'stock_adjustment', $adjustment['id'], ['SOURCE_MOVEMENT_ID' => $move]);
                } break;
            case 'delivery_proof':$this->delivery($i, $id, $d);
                break;
            case 'customer_sale_return':$this->salesReturn($i, $id, $d);
                break;
            case 'trip':
                $stops = $this->s->related($i, 'trip_destination', 'TRIP_ID', $id);
                if (!$stops) {
                    throw new Problem('A completed trip needs delivery destinations');
                }
                foreach ($stops as $stop) {
                    if (!in_array($this->d($stop)['DELIVERY_STATUS'], ['delivered','completed_with_rejection'])) {
                        throw new Problem('Confirm proof for each destination before completing the trip');
                    }
                }
                $this->tripCosts($i, $id);
                break;
            case 'fuel_transaction':case 'vehicle_maintenance':case 'trip_expense':case 'expense':
                $amount = $d['TOTAL_AMOUNT'] ?? $d['TOTAL_COST'] ?? $d['AMOUNT'] ?? 0;
                $this->s->journal($i, $source, $this->accountCode($i, $d['EXPENSE_ACCOUNT_ID'] ?? $d['ACCOUNT_ID'] ?? null, $type === 'expense' ? 'operating_expense' : 'fleet_expense'), $this->paymentCode($i, $d), $this->positive($amount, 'Expense'));
                if (!empty($d['TRIP_ID'])) {
                    $this->tripCosts($i, $d['TRIP_ID']);
                }break;
            case 'supplier_incentive':$this->s->journal($i, $source, 'incentive_receivable', 'incentive_income', $this->positive($d['ACTUAL_AMOUNT'], 'Benefit'));
                break;
            case 'supplier_incentive_receipt':
                $benefit = $this->s->semantic($i, 'supplier_incentive', $d['INCENTIVE_ID']);
                $amount = $this->positive($d['AMOUNT']);
                if ($benefit['STATUS'] !== 'verified' || $amount > $benefit['ACTUAL_AMOUNT'] - $benefit['AMOUNT_RECEIVED'] + 0.001) {
                    throw new Problem('Receipt exceeds verified outstanding benefit');
                }
                $this->s->journal($i, $source, 'bank', 'incentive_receivable', $amount);
                $this->s->patch($i, 'supplier_incentive', $d['INCENTIVE_ID'], ['AMOUNT_RECEIVED' => $benefit['AMOUNT_RECEIVED'] + $amount]);
                break;
            case 'payroll':
                $this->rollupPayroll($i, $id);
                $payroll = $this->s->semantic($i, 'payroll', $id);
                if ($payroll['TOTAL_GROSS'] <= 0) {
                    throw new Problem('Payroll needs earning lines');
                }
                $this->s->journal($i, $source.':net', 'salary_expense', 'payroll_payable', $payroll['TOTAL_NET']);
                $cooperativeTotal = 0;
                foreach ($this->s->related($i, 'payroll_item', 'PAYROLL_ID', $id) as $row) {
                    $line = $this->d($row);
                    $employment = $this->s->semantic($i, 'staff_employment', $line['EMPLOYMENT_ID']);
                    foreach (['SAVINGS_DEDUCTION' => ['DEFAULT_SAVINGS_ACCOUNT_ID','savings_contribution','savings_payable'],'LOAN_DEDUCTION' => ['DEFAULT_LOAN_ACCOUNT_ID','loan_repayment','staff_loans_receivable']] as $alias => [$link,$kind,$credit]) {
                        $amount = $line[$alias] ?? 0;
                        if ($amount <= 0) {
                            continue;
                        }if (empty($employment[$link])) {
                            throw new Problem('Employment requires a shared Cooperative account for this deduction');
                        }
                        if (($this->s->semantic($i, 'member_account', $employment[$link])['MEMBER_ID'] ?? null) !== ($employment['COOPERATIVE_MEMBER_ID'] ?? null)) {
                            throw new Problem('Payroll Cooperative account belongs to another member');
                        }
                        (new SharedServices($this->s))->changeMemberBalance($i, $employment[$link], $kind, $amount);
                        $this->s->save($i, 'cooperative_transaction', ['MEMBER_ACCOUNT_ID' => $employment[$link],'POSTING_DATE' => $payroll['PAYMENT_DATE'],'AMOUNT' => $amount,'TRANSACTION_TYPE' => $kind,'SOURCE' => 'payroll:'.$id.':'.$row['id'],'STATUS' => 'posted']);
                        $this->s->journal($i, $source.':'.$row['id'].':'.$alias, 'salary_expense', $credit, $amount);
                        $cooperativeTotal += $amount;
                    }
                }
                $this->s->journal($i, $source.':deductions', 'salary_expense', 'deductions_payable', $payroll['TOTAL_DEDUCTIONS'] - $cooperativeTotal);
                break;
            case 'payroll_payment':
                $item = $this->s->semantic($i, 'payroll_item', $d['PAYROLL_ITEM_ID']);
                $payroll = $this->s->semantic($i, 'payroll', $item['PAYROLL_ID']);
                if ($item['EMPLOYMENT_ID'] !== $d['EMPLOYMENT_ID'] || $payroll['STATUS'] !== 'posted') {
                    throw new Problem('Payment must match an approved payroll line');
                }
                $paid = $this->sum(array_filter($this->s->related($i, 'payroll_payment', 'PAYROLL_ITEM_ID', $d['PAYROLL_ITEM_ID']), fn ($r) => $r['id'] !== $id && $this->d($r)['STATUS'] === 'completed'), 'AMOUNT');
                if ($paid + $d['AMOUNT'] > $item['NET_PAY'] + 0.001) {
                    throw new Problem('Payment exceeds net payroll due');
                }
                $this->s->journal($i, $source, 'payroll_payable', 'bank', $this->positive($d['AMOUNT']));
                break;
        }
    }
    public function deleted(array $actor, string $type, array $before): void
    {
        $i = (int)$actor['institution_id'];
        if ($type === 'purchaseItem') {
            $this->rollupPurchase($i, $before['PURCHASE_ID']);
        }
        if ($type === 'customer_sale_item') {
            $this->rollupSale($i, $before['SALE_ID']);
        }
        if ($type === 'payroll_item') {
            $this->rollupPayroll($i, $before['PAYROLL_ID']);
        }
        if ($type === 'trip_destination') {
            $rows = $this->s->related($i, 'trip_destination', 'TRIP_ID', $before['TRIP_ID']);
            $this->s->patch($i, 'trip', $before['TRIP_ID'], ['DESTINATION_COUNT' => count($rows),'TOTAL_QUANTITY' => $this->sum($rows, 'QUANTITY')]);
        }
    }
    private function accountCode(int $i, ?int $id, string $fallback): string
    {
        return $id ? $this->s->semantic($i, 'account', $id)['CODE'] : $fallback;
    }
    private function paymentCode(int $i, array $d): string
    {
        if (!empty($d['PAYMENT_ACCOUNT_ID'])) {
            return $this->accountCode($i, $d['PAYMENT_ACCOUNT_ID'], 'bank');
        }
        if (!empty($d['BANK_ACCOUNT_ID'])) {
            return $this->accountCode($i, $this->s->semantic($i, 'bank_account', $d['BANK_ACCOUNT_ID'])['ACCOUNT_ID'], 'bank');
        }return 'bank';
    }
    private function prepaidCode(int $i, array $d): string
    {
        $purchase = $d;
        if (!empty($d['PURCHASE_ITEM_ID']) || !empty($d['SOURCE_PURCHASE_ITEM_ID'])) {
            $item = $this->s->semantic($i, 'purchaseItem', $d['PURCHASE_ITEM_ID'] ?? $d['SOURCE_PURCHASE_ITEM_ID']);
            $purchase = $this->s->semantic($i, 'purchase', $item['PURCHASE_ID']);
        } elseif (!empty($d['PURCHASE_ID'])) {
            $purchase = $this->s->semantic($i, 'purchase', $d['PURCHASE_ID']);
        }
        if (!empty($purchase['PREPAID_ACCOUNT_ID'])) {
            return $this->accountCode($i, $purchase['PREPAID_ACCOUNT_ID'], 'supplier_prepaid');
        }
        if (!empty($purchase['SUPPLIER_ID'])) {
            return $this->accountCode($i, $this->s->semantic($i, 'supplier', $purchase['SUPPLIER_ID'])['PREPAID_ACCOUNT_ID'] ?? null, 'supplier_prepaid');
        }return 'supplier_prepaid';
    }
    private function entitlement(int $i, int $item): float
    {
        $base = $this->s->semantic($i, 'purchaseItem', $item)['ORIGINAL_QUANTITY'];
        return $base + $this->sum(array_filter($this->s->related($i, 'purchase_adjustment', 'PURCHASE_ITEM_ID', $item), fn ($r) => $this->d($r)['STATUS'] === 'posted'), 'QUANTITY');
    }
    private function rollupPurchase(int $i, int $id): void
    {
        $items = $this->s->related($i, 'purchaseItem', 'PURCHASE_ID', $id);
        $adjustments = $this->sum(array_filter($this->s->related($i, 'purchase_adjustment', 'PURCHASE_ID', $id), fn ($r) => $this->d($r)['STATUS'] === 'posted'), 'AMOUNT');
        $net = $this->sum($items, 'AMOUNT') + $adjustments;
        $gross = array_sum(array_map(fn ($r) => $this->d($r)['ORIGINAL_QUANTITY'] * $this->d($r)['UNIT_PRICE'], $items)) + $adjustments;
        $this->s->patch($i, 'purchase', $id, ['GROSS_AMOUNT' => round($gross, 2),'DISCOUNT_AMOUNT' => round($gross - $net, 2),'NET_AMOUNT' => round($net, 2)]);
    }
    private function rollupSale(int $i, int $id): void
    {
        $items = $this->s->related($i, 'customer_sale_item', 'SALE_ID', $id);
        $sale = $this->s->semantic($i, 'customer_sales', $id);
        $net = $this->sum($items, 'LINE_AMOUNT');
        $tax = $this->sum($items, 'TAX_AMOUNT');
        $gross = array_sum(array_map(fn ($r) => $this->d($r)['QUANTITY'] * $this->d($r)['AGREED_UNIT_PRICE'], $items));
        $this->s->patch($i, 'customer_sales', $id, ['GROSS_AMOUNT' => round($gross, 2),'DISCOUNT_AMOUNT' => round($gross + $tax - $net, 2),'TAX_AMOUNT' => round($tax, 2),'NET_AMOUNT' => round($net, 2),'BALANCE_DUE' => round($net - $sale['AMOUNT_PAID'], 2)]);
    }
    private function rollupPayroll(int $i, int $id): void
    {
        $items = $this->s->related($i, 'payroll_item', 'PAYROLL_ID', $id);
        $gross = $this->sum($items, 'GROSS_PAY');
        $net = $this->sum($items, 'NET_PAY');
        $this->s->patch($i, 'payroll', $id, ['TOTAL_GROSS' => $gross,'TOTAL_NET' => $net,'TOTAL_DEDUCTIONS' => $gross - $net]);
    }
    private function loading(array $actor, int $id, array $d): void
    {
        $i = (int)$actor['institution_id'];
        $warehouseDelivered = 0;
        $item = $this->s->semantic($i, 'purchaseItem', $d['PURCHASE_ITEM_ID']);
        $purchase = $this->s->semantic($i, 'purchase', $item['PURCHASE_ID']);
        $c = $this->s->catalog;
        foreach ($d['ALLOCATION_ROWS'] as $allocation) {
            $qty = $allocation[$c->fields['cement_loading']['QUANTITY']];
            $customer = $allocation[$c->fields['customer_sales']['CUSTOMER_ID']] ?? null;
            $warehouse = $allocation[$c->fields['customer_sales']['WAREHOUSE_ID']] ?? null;
            if ($warehouse) {
                $warehouseDelivered += $qty;
                $this->createMovement($i, ['PRODUCT_ID' => $item['PRODUCT_ID'],'DESTINATION_LOCATION_ID' => $warehouse,'STOCK_CONDITION' => 'good','QTY_BASE' => $qty * $item['UNIT_CONVERSION_SNAPSHOT']['factor'],'UNIT_COST' => $item['NET_UNIT_COST'] / $item['UNIT_CONVERSION_SNAPSHOT']['factor'],'PURCHASE_ITEM_ID' => $d['PURCHASE_ITEM_ID'],'LOADING_ID' => $id]);
            } else {
                $sale = $this->s->save($i, 'customer_sales', ['CUSTOMER_ID' => $customer,'SOURCE_LOADING_ID' => $id,'SALE_DATE' => substr($d['LOADING_DATE'], 0, 10),'CURRENCY' => $purchase['CURRENCY'],'OWNERSHIP_TRANSFER_POLICY' => $purchase['OWNERSHIP_TRANSFER_POLICY'],'GROSS_AMOUNT' => 0,'NET_AMOUNT' => 0,'TAX_AMOUNT' => 0,'DISCOUNT_AMOUNT' => 0,'AMOUNT_PAID' => 0,'BALANCE_DUE' => 0,'DELIVERY_STATUS' => 'pending','STATUS' => 'draft']);
                $line = ['SALE_ID' => $sale['id'],'PRODUCT_ID' => $item['PRODUCT_ID'],'UNIT_ID' => $item['UNIT_ID'],'QUANTITY' => $qty,'SOURCE_PURCHASE_ITEM_ID' => $d['PURCHASE_ITEM_ID'],'SOURCE_LOADING_ID' => $id,'STATUS' => 'draft'];
                $priceKey = $c->fields['customer_sale_item']['AGREED_UNIT_PRICE'];
                if (isset($allocation[$priceKey])) {
                    $line['AGREED_UNIT_PRICE'] = $allocation[$priceKey];
                }
                $line = $this->prepare($actor, 'customer_sale_item', $line, []);
                $this->s->save($i, 'customer_sale_item', $line);
                $this->rollupSale($i, $sale['id']);
                // Revenue is posted only at the institution's declared ownership event.
                if ($purchase['OWNERSHIP_TRANSFER_POLICY'] === 'loading-transfer') {
                    $this->invoice($i, $sale['id'], $this->s->semantic($i, 'customer_sales', $sale['id']));
                }
            }
        }
        $this->s->patch($i, 'purchaseItem', $d['PURCHASE_ITEM_ID'], ['LOADED_QTY' => $item['LOADED_QTY'] + $d['QUANTITY'],'DELIVERED_QTY' => $item['DELIVERED_QTY'] + $warehouseDelivered,'FULLY_DELIVERED' => $item['DELIVERED_QTY'] + $warehouseDelivered >= $this->entitlement($i, $d['PURCHASE_ITEM_ID']),'FIRST_LOADED_AT' => $item['FIRST_LOADED_AT'] ?? gmdate('c')]);
    }
    private function applyDeposits(int $i, int $saleId): array
    {
        $sale = $this->s->semantic($i, 'customer_sales', $saleId);
        $needed = (int)round(($sale['NET_AMOUNT'] - $sale['AMOUNT_PAID']) * 100);
        $applied = 0;
        foreach ($this->s->related($i, 'customer_payment', 'CUSTOMER_ID', $sale['CUSTOMER_ID']) as $r) {
            $payment = $this->d($r);
            if (!empty($payment['SALE_ID']) || $payment['STATUS'] !== 'completed' || $needed <= 0) {
                continue;
            }
            $used = (int)$this->s->run('SELECT COALESCE(SUM(amount_minor),0) FROM payment_allocations WHERE institution_id=? AND payment_id=?', [$i,$r['id']])->fetchColumn();
            $amount = min($needed, (int)round($payment['AMOUNT'] * 100) - $used);
            if ($amount <= 0) {
                continue;
            }
            $this->s->run('INSERT INTO payment_allocations(institution_id,payment_id,sale_id,amount_minor) VALUES (?,?,?,?) ON CONFLICT(institution_id,payment_id,sale_id) DO UPDATE SET amount_minor=amount_minor+excluded.amount_minor', [$i,$r['id'],$saleId,$amount]);
            $needed -= $amount;
            $applied += $amount;
        }
        if ($applied) {
            $sale['AMOUNT_PAID'] += $applied / 100;
            $sale['BALANCE_DUE'] = max(0, $sale['NET_AMOUNT'] - $sale['AMOUNT_PAID']);
            $this->s->patch($i, 'customer_sales', $saleId, ['AMOUNT_PAID' => $sale['AMOUNT_PAID'],'BALANCE_DUE' => $sale['BALANCE_DUE']]);
        }return $sale;
    }
    private function invoice(int $i, int $id, array $sale): void
    {
        $items = $this->s->related($i, 'customer_sale_item', 'SALE_ID', $id);
        if (!$items) {
            throw new Problem('Add priced sale items before invoicing');
        }
        $this->rollupSale($i, $id);
        $sale = $this->applyDeposits($i, $id);
        $customer = $this->s->semantic($i, 'customer', $sale['CUSTOMER_ID']);
        $outstanding = $this->sum(array_filter($this->s->related($i, 'customer_sales', 'CUSTOMER_ID', $sale['CUSTOMER_ID']), fn ($r) => $r['id'] !== $id && in_array($this->d($r)['STATUS'], ['invoiced','partially_invoiced'])), 'BALANCE_DUE');
        $limit = $customer['CREDIT_LIMIT'] ?? 0;
        if ($limit > 0 && $outstanding + $sale['BALANCE_DUE'] > $limit + 0.001) {
            throw new Problem('Customer credit limit exceeded');
        }
        if (!$this->s->settings($i)['allowCreditSales'] && $sale['BALANCE_DUE'] > 0) {
            throw new Problem('Credit sales are disabled');
        }
        foreach ($items as $row) {
            $line = $this->d($row);
            if (!empty($line['SOURCE_LOADING_ID'])) {
                $item = $this->s->semantic($i, 'purchaseItem', $line['SOURCE_PURCHASE_ITEM_ID']);
                $this->s->journal($i, 'sale:'.$id.':direct:'.$row['id'], 'cost_of_sales', $this->prepaidCode($i, ['PURCHASE_ID' => $item['PURCHASE_ID']]), round($item['NET_UNIT_COST'] * $line['QUANTITY'], 2));
            } else {
                if (empty($sale['WAREHOUSE_ID'])) {
                    throw new Problem('Warehouse sale requires a source location');
                }
                $this->createMovement($i, ['PRODUCT_ID' => $line['PRODUCT_ID'],'SOURCE_LOCATION_ID' => $sale['WAREHOUSE_ID'],'STOCK_CONDITION' => 'good','QTY_BASE' => $line['QUANTITY_BASE'],'SALE_ITEM_ID' => $row['id']]);
            }
        }
        $this->s->journal($i, 'sale:'.$id.':revenue', 'receivables', 'sales_income', $sale['NET_AMOUNT'] - $sale['TAX_AMOUNT']);
        $this->s->journal($i, 'sale:'.$id.':tax', 'receivables', 'tax_payable', $sale['TAX_AMOUNT']);
        $this->s->journal($i, 'sale:'.$id.':deposit', 'customer_deposits', 'receivables', min($sale['AMOUNT_PAID'], $sale['NET_AMOUNT']));
        $this->s->run('INSERT INTO sale_recognitions(institution_id,sale_id,source,amount_minor,tax_minor,quantity,created_at) VALUES (?,?,?,?,?,?,?)', [$i,$id,'invoice:'.$id,(int)round($sale['NET_AMOUNT'] * 100),(int)round($sale['TAX_AMOUNT'] * 100),$this->sum($items, 'QUANTITY'),gmdate('c')]);
        $this->s->patch($i, 'customer_sales', $id, ['STATUS' => 'invoiced']);
    }
    private function stock(int $i, int $location, int $product, string $condition): array
    {
        foreach ($this->s->all($i, 'inventory_stock') as $r) {
            $d = $this->d($r);
            if ($d['LOCATION_ID'] === $location && $d['PRODUCT_ID'] === $product && $d['STOCK_CONDITION'] === $condition) {
                return $d + ['_ID' => $r['id']];
            }
        }
        return ['LOCATION_ID' => $location,'PRODUCT_ID' => $product,'STOCK_CONDITION' => $condition,'QUANTITY_ON_HAND' => 0,'QUANTITY_RESERVED' => 0,'QUANTITY_AVAILABLE' => 0,'AVERAGE_UNIT_COST' => 0,'TOTAL_VALUE' => 0,'STATUS' => 'active','_ID' => null];
    }
    private function createMovement(int $i, array $d): int
    {
        $d += ['MOVEMENT_DATE' => gmdate('c'),'MOVEMENT_TYPE' => 'business_posting','COST_POLICY' => $this->s->settings($i)['inventoryValuationMethod'],'STATUS' => 'confirmed'];
        $row = $this->s->save($i, 'inventory_movement', $d);
        $this->movement($i, $row['id'], $d);
        return $row['id'];
    }
    private function movement(int $i, int $id, array $d): void
    {
        $qty = $this->positive($d['QTY_BASE']);
        $cost = (float)($d['UNIT_COST'] ?? 0);
        $condition = $d['STOCK_CONDITION'];
        $alloc = [];
        if (!empty($d['SOURCE_LOCATION_ID'])) {
            $stock = $this->stock($i, $d['SOURCE_LOCATION_ID'], $d['PRODUCT_ID'], $condition);
            if ($stock['QUANTITY_AVAILABLE'] + 0.000001 < $qty) {
                throw new Problem('Insufficient available stock');
            }
            $cost = $stock['AVERAGE_UNIT_COST'];
            {
                $fifo = $this->s->settings($i)['inventoryValuationMethod'] === 'fifo';
                $remaining = $qty;
                $total = 0;
                foreach ($this->s->related($i, 'inventory_cost_layer', 'STOCK_ID', $stock['_ID']) as $row) {
                    $layer = $this->d($row);
                    $used = min($remaining, $layer['REMAINING_QTY']);
                    if ($used <= 0) {
                        continue;
                    }
                    $total += $used * $layer['UNIT_COST'];
                    $remaining -= $used;
                    $alloc[] = ['layer' => $row['id'],'quantity' => $used,'unitCost' => $layer['UNIT_COST']];
                    $this->s->patch($i, 'inventory_cost_layer', $row['id'], ['REMAINING_QTY' => $layer['REMAINING_QTY'] - $used]);
                    if ($remaining <= 0.000001) {
                        break;
                    }
                }
                if ($remaining > 0.000001) {
                    throw new Problem('Inventory cost layers do not reconcile');
                }if ($fifo) {
                    $cost = $total / $qty;
                } else {
                    $alloc = [];
                }
            }
            $this->updateStock($i, $stock, -$qty, $cost);
            $this->s->journal($i, 'movement:'.$id.':issue', !empty($d['DESTINATION_LOCATION_ID']) ? 'inventory_transit' : (!empty($d['SALE_ITEM_ID']) ? 'cost_of_sales' : (($d['MOVEMENT_TYPE'] ?? '') === 'write_off' ? 'bad_goods_expense' : 'stock_variance')), 'inventory', round($qty * $cost, 2));
        }
        if (!empty($d['DESTINATION_LOCATION_ID'])) {
            if ($cost < 0) {
                throw new Problem('Unit cost cannot be negative');
            }
            $stock = $this->stock($i, $d['DESTINATION_LOCATION_ID'], $d['PRODUCT_ID'], $condition);
            $stockId = $this->updateStock($i, $stock, $qty, $cost);
            $this->s->save($i, 'inventory_cost_layer', ['STOCK_ID' => $stockId,'PRODUCT_ID' => $d['PRODUCT_ID'],'LOCATION_ID' => $d['DESTINATION_LOCATION_ID'],'SOURCE_MOVEMENT_ID' => $id,'RECEIPT_DATE' => gmdate('c'),'ORIGINAL_QTY' => $qty,'REMAINING_QTY' => $qty,'UNIT_COST' => $cost,'STATUS' => 'active']);
            $this->s->journal($i, 'movement:'.$id.':receipt', 'inventory', !empty($d['SOURCE_LOCATION_ID']) ? 'inventory_transit' : (!empty($d['LOADING_ID']) ? $this->prepaidCode($i, $d) : (($d['SOURCE_RECORD_TYPE_CODE'] ?? '') === 'customer_sale_return' ? 'cost_of_sales' : 'stock_variance')), round($qty * $cost, 2));
        }
        $this->s->patch($i, 'inventory_movement', $id, ['UNIT_COST' => $cost,'TOTAL_COST' => round($qty * $cost, 2),'FIFO_ALLOCATIONS' => $alloc,'EFFECTIVE_AT' => gmdate('c')]);
    }
    private function updateStock(int $i, array $stock, float $delta, float $cost): int
    {
        $id = $stock['_ID'];
        unset($stock['_ID']);
        $stock['QUANTITY_ON_HAND'] = round($stock['QUANTITY_ON_HAND'] + $delta, 6);
        $stock['QUANTITY_AVAILABLE'] = $stock['QUANTITY_ON_HAND'] - $stock['QUANTITY_RESERVED'];
        $stock['TOTAL_VALUE'] = max(0, round($stock['TOTAL_VALUE'] + $delta * $cost, 2));
        $stock['AVERAGE_UNIT_COST'] = $stock['QUANTITY_ON_HAND'] > 0 ? $stock['TOTAL_VALUE'] / $stock['QUANTITY_ON_HAND'] : 0;
        $stock['LAST_MOVEMENT_AT'] = gmdate('c');
        return $this->s->save($i, 'inventory_stock', $stock, $id)['id'];
    }
    private function delivery(int $i, int $id, array $d): void
    {
        $sale = $this->s->semantic($i, 'customer_sales', $d['SALE_ID']);
        if ($sale['OWNERSHIP_TRANSFER_POLICY'] === 'delivery-transfer' && $sale['STATUS'] === 'draft') {
            $sale = $this->applyDeposits($i, $d['SALE_ID']);
        }
        if (!empty($d['TRIP_DESTINATION_ID'])) {
            $stop = $this->s->semantic($i, 'trip_destination', $d['TRIP_DESTINATION_ID']);
            if (($stop['SALE_ID'] ?? null) !== $d['SALE_ID']) {
                throw new Problem('Delivery proof does not match this destination’s sale');
            }
            $proofs = $this->s->related($i, 'delivery_proof', 'TRIP_DESTINATION_ID', $d['TRIP_DESTINATION_ID']);
            $priorStop = 0;
            $acceptedAtStop = 0;
            foreach ($proofs as $p) {
                if ($p['id'] !== $id && $this->d($p)['STATUS'] === 'confirmed') {
                    $acceptedAtStop += $this->d($p)['ACCEPTED_QUANTITY'];
                    $priorStop += $this->d($p)['ACCEPTED_QUANTITY'] + ($this->d($p)['REJECTED_QUANTITY'] ?? 0);
                }
            }
            if ($priorStop + $d['ACCEPTED_QUANTITY'] + ($d['REJECTED_QUANTITY'] ?? 0) > $stop['QUANTITY'] + 0.000001) {
                throw new Problem('Proof exceeds this destination quantity');
            }
        }
        $lines = $this->s->related($i, 'customer_sale_item', 'SALE_ID', $d['SALE_ID']);
        $total = $this->sum($lines, 'QUANTITY');
        $proofs = array_filter($this->s->related($i, 'delivery_proof', 'SALE_ID', $d['SALE_ID']), fn ($r) => $r['id'] !== $id && $this->d($r)['STATUS'] === 'confirmed');
        $accepted = $d['ACCEPTED_QUANTITY'];
        $rejected = $d['REJECTED_QUANTITY'] ?? 0;
        if ($accepted < 0 || $rejected < 0 || $accepted + $rejected <= 0) {
            throw new Problem('Enter nonnegative delivered quantities');
        }
        $prior = $this->sum($proofs, 'ACCEPTED_QUANTITY');
        $priorRejected = $this->sum($proofs, 'REJECTED_QUANTITY');
        if ($accepted + $rejected + $prior + $priorRejected > $total + 0.000001) {
            throw new Problem('Proof exceeds remaining delivery quantity');
        }
        if ($sale['OWNERSHIP_TRANSFER_POLICY'] === 'delivery-transfer' && $accepted > 0) {
            $skip = $prior;
            $remaining = $accepted;
            $net = 0;
            $tax = 0;
            foreach ($lines as $row) {
                $line = $this->d($row);
                $already = min($skip, $line['QUANTITY']);
                $skip -= $already;
                $used = min($remaining, $line['QUANTITY'] - $already);
                if ($used <= 0) {
                    continue;
                }$remaining -= $used;
                $net += $line['LINE_AMOUNT'] * $used / $line['QUANTITY'];
                $tax += $line['TAX_AMOUNT'] * $used / $line['QUANTITY'];
                if (!empty($line['SOURCE_PURCHASE_ITEM_ID'])) {
                    $item = $this->s->semantic($i, 'purchaseItem', $line['SOURCE_PURCHASE_ITEM_ID']);
                    $this->s->journal($i, 'delivery:'.$id.':cost:'.$row['id'], 'cost_of_sales', $this->prepaidCode($i, ['PURCHASE_ID' => $item['PURCHASE_ID']]), round($item['NET_UNIT_COST'] * $used, 2));
                } else {
                    if (empty($sale['WAREHOUSE_ID'])) {
                        throw new Problem('Delivery requires an inventory source');
                    }
                    $this->createMovement($i, ['PRODUCT_ID' => $line['PRODUCT_ID'],'SOURCE_LOCATION_ID' => $sale['WAREHOUSE_ID'],'STOCK_CONDITION' => 'good','QTY_BASE' => $used * $line['UNIT_CONVERSION_SNAPSHOT']['factor'],'SALE_ITEM_ID' => $row['id']]);
                }
            }
            $recognized = $this->s->run('SELECT COALESCE(SUM(amount_minor),0) AS amount,COALESCE(SUM(tax_minor),0) AS tax FROM sale_recognitions WHERE institution_id=? AND sale_id=?', [$i,$d['SALE_ID']])->fetch(\PDO::FETCH_ASSOC);
            $net = round($net, 2);
            $tax = round($tax, 2);
            if ($accepted + $prior >= $total - 0.000001) {
                $net = round($sale['NET_AMOUNT'] - $recognized['amount'] / 100, 2);
                $tax = round($sale['TAX_AMOUNT'] - $recognized['tax'] / 100, 2);
            }
            $customer = $this->s->semantic($i, 'customer', $sale['CUSTOMER_ID']);
            $outstanding = 0;
            foreach ($this->s->related($i, 'customer_sales', 'CUSTOMER_ID', $sale['CUSTOMER_ID']) as $other) {
                $s = $this->d($other);
                if (in_array($s['STATUS'], ['invoiced','partially_invoiced'])) {
                    $outstanding += $s['BALANCE_DUE'];
                }
            }
            $newDue = max(0, $recognized['amount'] / 100 + $net - $sale['AMOUNT_PAID']);
            $extraDue = max(0, $newDue - ($sale['STATUS'] === 'draft' ? 0 : $sale['BALANCE_DUE']));
            if (($customer['CREDIT_LIMIT'] ?? 0) > 0 && $outstanding + $extraDue > $customer['CREDIT_LIMIT'] + 0.001) {
                throw new Problem('Customer credit limit exceeded');
            }
            if (!$this->s->settings($i)['allowCreditSales'] && $newDue > 0) {
                throw new Problem('Receive payment before accepting delivery; credit sales are disabled');
            }
            $this->s->journal($i, 'delivery:'.$id.':income', 'receivables', 'sales_income', $net - $tax);
            $this->s->journal($i, 'delivery:'.$id.':tax', 'receivables', 'tax_payable', $tax);
            $deposit = min($net, max(0, $sale['AMOUNT_PAID'] - $recognized['amount'] / 100));
            $this->s->journal($i, 'delivery:'.$id.':deposit', 'customer_deposits', 'receivables', $deposit);
            $this->s->run('INSERT INTO sale_recognitions(institution_id,sale_id,source,amount_minor,tax_minor,quantity,created_at) VALUES (?,?,?,?,?,?,?)', [$i,$d['SALE_ID'],'delivery:'.$id,(int)round($net * 100),(int)round($tax * 100),$accepted,gmdate('c')]);
            $this->s->patch($i, 'customer_sales', $d['SALE_ID'], ['STATUS' => $accepted + $prior >= $total - 0.000001 ? 'invoiced' : 'partially_invoiced','BALANCE_DUE' => max(0, round($recognized['amount'] / 100 + $net - $sale['AMOUNT_PAID'], 2))]);
        }
        $this->s->patch($i, 'customer_sales', $d['SALE_ID'], ['DELIVERY_STATUS' => $accepted + $prior >= $total ? 'delivered' : 'partial','DELIVERED_AT' => $d['DELIVERED_DATE']]);
        if (!empty($d['TRIP_DESTINATION_ID'])) {
            $this->s->patch($i, 'trip_destination', $d['TRIP_DESTINATION_ID'], ['PROOF_ID' => $id,'DELIVERY_STATUS' => $acceptedAtStop + $accepted >= $stop['QUANTITY'] ? 'delivered' : ($priorStop + $accepted + $rejected >= $stop['QUANTITY'] ? 'completed_with_rejection' : 'partial'),'DELIVERED_AT' => $d['DELIVERED_DATE']]);
        }
        $remaining = $accepted;
        $skip = $prior;
        foreach ($lines as $row) {
            $line = $this->d($row);
            if (empty($line['SOURCE_PURCHASE_ITEM_ID']) || $remaining <= 0) {
                continue;
            }
            $already = min($skip, $line['QUANTITY']);
            $skip -= $already;
            $used = min($remaining, $line['QUANTITY'] - $already);
            $remaining -= $used;
            $item = $this->s->semantic($i, 'purchaseItem', $line['SOURCE_PURCHASE_ITEM_ID']);
            $delivered = $item['DELIVERED_QTY'] + $used;
            $this->s->patch($i, 'purchaseItem', $line['SOURCE_PURCHASE_ITEM_ID'], ['DELIVERED_QTY' => $delivered,'FULLY_DELIVERED' => $delivered >= $this->entitlement($i, $line['SOURCE_PURCHASE_ITEM_ID'])]);
        }
    }
    private function salesReturn(int $i, int $id, array $d): void
    {
        $sale = $this->s->semantic($i, 'customer_sales', $d['SALE_ID']);
        $line = $this->s->semantic($i, 'customer_sale_item', $d['SALE_ITEM_ID']);
        if ($line['SALE_ID'] !== $d['SALE_ID'] || !in_array($sale['STATUS'], ['invoiced','partially_invoiced'])) {
            throw new Problem('Return requires a matching invoice line');
        }
        $qty = $this->positive($d['QUANTITY']);
        $previous = $this->sum(array_filter($this->s->related($i, 'customer_sale_return', 'SALE_ITEM_ID', $d['SALE_ITEM_ID']), fn ($r) => $r['id'] !== $id && $this->d($r)['STATUS'] === 'confirmed'), 'QUANTITY');
        $eligible = $line['QUANTITY'];
        if ($sale['OWNERSHIP_TRANSFER_POLICY'] === 'delivery-transfer') {
            $accepted = (float)$this->s->run('SELECT COALESCE(SUM(quantity),0) FROM sale_recognitions WHERE institution_id=? AND sale_id=?', [$i,$d['SALE_ID']])->fetchColumn();
            foreach ($this->s->related($i, 'customer_sale_item', 'SALE_ID', $d['SALE_ID']) as $r) {
                if ($r['id'] === $d['SALE_ITEM_ID']) {
                    break;
                }$accepted -= $this->d($r)['QUANTITY'];
            }$eligible = min($eligible, max(0, $accepted));
        }
        if ($qty + $previous > $eligible) {
            throw new Problem('Returned quantity exceeds transferred sale quantity');
        }
        $credit = round($line['LINE_AMOUNT'] * $qty / $line['QUANTITY'], 2);
        $receivable = min($credit, $sale['BALANCE_DUE']);
        $refund = round($credit - $receivable, 2);
        $tax = round(($line['TAX_AMOUNT'] ?? 0) * $qty / $line['QUANTITY'], 2);
        $revenue = $credit - $tax;
        $remainingReceivable = $receivable;
        foreach (['sales_returns' => $revenue,'tax_payable' => $tax] as $debit => $amount) {
            $againstDue = min($amount, $remainingReceivable);
            $this->s->journal($i, 'return:'.$id.':'.$debit.':credit', $debit, 'receivables', $againstDue);
            $remainingReceivable -= $againstDue;
            $this->s->journal($i, 'return:'.$id.':'.$debit.':refund', $debit, 'bank', $amount - $againstDue);
        }
        $this->s->patch($i, 'customer_sales', $d['SALE_ID'], ['BALANCE_DUE' => round($sale['BALANCE_DUE'] - $receivable, 2)]);
        $this->s->patch($i, 'customer_sale_return', $id, ['REFUND_AMOUNT' => $refund]);
        if (in_array($d['DISPOSITION'], ['restock','damaged'])) {
            $receipt = null;
            $location = $sale['WAREHOUSE_ID'] ?? null;
            if (!empty($d['STOCK_MOVEMENT_ID'])) {
                $receipt = $this->s->semantic($i, 'inventory_movement', $d['STOCK_MOVEMENT_ID']);
                if ($receipt['STATUS'] !== 'draft' || !empty($receipt['SOURCE_LOCATION_ID']) || $receipt['PRODUCT_ID'] !== $line['PRODUCT_ID'] || abs($receipt['QTY_BASE'] - $qty * $line['UNIT_CONVERSION_SNAPSHOT']['factor']) > 0.000001) {
                    throw new Problem('Choose an unposted return receipt for this product and quantity');
                }$location = $receipt['DESTINATION_LOCATION_ID'] ?? null;
            }
            if (!$location) {
                throw new Problem('Choose a draft inventory receipt with the intended return warehouse');
            }
            $cost = 0;
            if (!empty($line['SOURCE_PURCHASE_ITEM_ID'])) {
                $cost = $this->s->semantic($i, 'purchaseItem', $line['SOURCE_PURCHASE_ITEM_ID'])['NET_UNIT_COST'] / $line['UNIT_CONVERSION_SNAPSHOT']['factor'];
            } else {
                foreach ($this->s->related($i, 'inventory_movement', 'SALE_ITEM_ID', $d['SALE_ITEM_ID']) as $r) {
                    $cost = $this->d($r)['UNIT_COST'];
                }
            }
            $movement = ['PRODUCT_ID' => $line['PRODUCT_ID'],'DESTINATION_LOCATION_ID' => $location,'STOCK_CONDITION' => $d['DISPOSITION'] === 'damaged' ? 'damaged' : 'good','QTY_BASE' => $qty * $line['UNIT_CONVERSION_SNAPSHOT']['factor'],'UNIT_COST' => $cost,'SOURCE_RECORD_TYPE_CODE' => 'customer_sale_return','SOURCE_RECORD_ID' => $id,'SALE_ITEM_ID' => $d['SALE_ITEM_ID']];
            if ($receipt) {
                $movement = array_replace($receipt, $movement, ['STATUS' => 'confirmed']);
                $this->s->save($i, 'inventory_movement', $movement, $d['STOCK_MOVEMENT_ID']);
                $this->movement($i, $d['STOCK_MOVEMENT_ID'], $movement);
                $movementId = $d['STOCK_MOVEMENT_ID'];
            } else {
                $movementId = $this->createMovement($i, $movement);
            }
            $this->s->patch($i, 'customer_sale_return', $id, ['STOCK_MOVEMENT_ID' => $movementId]);
        }
    }
    private function tripCosts(int $i, int $id): void
    {
        $fuel = $this->sum(array_filter($this->s->related($i, 'fuel_transaction', 'TRIP_ID', $id), fn ($r) => $this->d($r)['STATUS'] === 'posted'), 'TOTAL_AMOUNT');
        $maintenance = $this->sum(array_filter($this->s->related($i, 'vehicle_maintenance', 'TRIP_ID', $id), fn ($r) => $this->d($r)['STATUS'] === 'posted'), 'TOTAL_COST');
        $expenses = $this->sum(array_filter($this->s->related($i, 'trip_expense', 'TRIP_ID', $id), fn ($r) => $this->d($r)['STATUS'] === 'posted'), 'AMOUNT') + $this->sum(array_filter($this->s->related($i, 'expense', 'TRIP_ID', $id), fn ($r) => $this->d($r)['STATUS'] === 'posted'), 'AMOUNT');
        $this->s->patch($i, 'trip', $id, ['TOTAL_FUEL_COST' => $fuel,'TOTAL_MAINTENANCE_COST' => $maintenance,'TOTAL_TRIP_EXPENSE' => $expenses,'TOTAL_TRIP_COST' => $fuel + $maintenance + $expenses]);
    }
}
