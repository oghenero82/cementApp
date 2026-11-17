<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Native order arithmetic and order-to-cash posting, using shared Accounting only. */
final class NativeSales
{
    public const TYPES = ['customer_sales','customer_sale_item','customer_payment','delivery_proof'];
    public static function register($registry): void
    {
        $h = new self();
        $inputs = [['name' => 'recordTypeCode','type' => 'string'],['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']];
        foreach (['prepare' => 'beforeValidate','effects' => 'afterSave'] as $method => $stage) {
            $registry->register('cement.sales.'.$method, $h, $method, $inputs);
        }
    }
    public static function definition(array $d): array
    {
        $d['settings']['cementSales'] = 1;
        $d['settings']['cementOperations'] = ['read','insert','update','delete'];
        foreach (['prepare' => 'beforeValidate','effects' => 'afterSave'] as $method => $stage) {
            $d['services'][] = ['code' => 'cement_sales_'.$method,'service' => 'cement.sales.'.$method,'method' => $method,'stage' => $stage,'trigger' => 'true','order' => $method === 'prepare' ? -1000 : 1000,'parameters' => ['recordTypeCode' => $d['code'],'record' => ['source' => 'data'],'previous' => ['source' => 'old'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        }
        return $d;
    }
    private static function items(NativeRecords $r, int $id): array
    {
        return array_values(array_filter($r->rows('customer_sale_item'), fn ($x) => $x['SALE_ID'] === $id));
    }
    private static function paid(NativeRecords $r, int $id): int
    {
        return NativePayments::paid($r, $id);
    }
    private function price(NativeRecords $r, array $sale, array $item): ?array
    {
        $matches = [];
        $customer = $r->find('customer', $sale['CUSTOMER_ID']);
        $selected = $sale['PRICE_LIST_ID'] ?? $customer['DEFAULT_PRICE_LIST_ID'] ?? null;
        foreach ($r->rows('price_list_item') as $d) {
            $list = $r->find('price_list', $d['PRICE_LIST_ID']);
            if ($d['PRODUCT_ID'] !== $item['PRODUCT_ID'] || $d['UNIT_ID'] !== $item['UNIT_ID'] || $d['MIN_QUANTITY'] > $item['QUANTITY'] || (($d['MAX_QUANTITY'] ?? 0) > 0 && $d['MAX_QUANTITY'] < $item['QUANTITY']) || $list['STATUS'] !== 'active' || $d['STATUS'] !== 'active' || $list['CURRENCY'] !== $sale['CURRENCY'] || ($selected !== null && $d['PRICE_LIST_ID'] !== $selected)) {
                continue;
            }
            $date = $sale['SALE_DATE'];
            if ($list['VALID_FROM'] > $date || (!empty($list['VALID_TO']) && $list['VALID_TO'] < $date) || (!empty($d['VALID_FROM']) && $d['VALID_FROM'] > $date) || (!empty($d['VALID_TO']) && $d['VALID_TO'] < $date)) {
                continue;
            }
            $target = $d['CUSTOMER_ID'] ?? $list['CUSTOMER_ID'] ?? null;
            if ($target && $target !== $sale['CUSTOMER_ID']) {
                continue;
            }
            $matches[] = ['id' => $d['_ID'],'price' => $d['UNIT_PRICE'],'rank' => [$target ? 1 : 0,$d['PRIORITY'] ?? $list['PRIORITY'] ?? 0,(float)$d['MIN_QUANTITY'],$d['_ID']]];
        }usort($matches, fn ($a, $b) => $b['rank'] <=> $a['rank']);
        return $matches[0] ?? null;
    }
    public function prepare(string $recordTypeCode, array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $t = $recordTypeCode;
        $d = $r->decode($t, $record);
        $old = $r->decode($t, $previous);
        $f = $r->aliases($t);
        $origin = TrustedPlans::origin();
        $internal = in_array($origin, ['cement.sales.balance','cement.payment','cement.delivery','cement.return'], true);
        if ($t === 'customer_payment') {
            return NativePayments::prepare($record, $previous, $recordId, $operation, $context);
        }
        if ($old && ($old['STATUS'] ?? 'draft') !== 'draft') {
            if ($t !== 'customer_sales' || !$internal || $operation !== 'update') {
                throw ValidationException::field($f['STATUS'], 'Posted sale, payment and delivery records are immutable');
            }
            foreach ($old as $a => $v) {
                if (!in_array($a, ['AMOUNT_PAID','BALANCE_DUE','DELIVERY_STATUS','DELIVERED_AT'], true) && ($d[$a] ?? null) != $v) {
                    throw ValidationException::field($f['STATUS'], 'Posted sale terms are immutable');
                }
            }
            return ['proposedData' => $r->encode($t, ['AMOUNT_PAID' => NativeLedger::decimal(self::paid($r, $recordId)),'BALANCE_DUE' => NativeLedger::decimal(NativeReturns::balance($r, $old + ['_ID' => $recordId]))])];
        }
        if ($t === 'customer_sale_item') {
            NativeReservations::guardLine($r, $old, $d, $operation);
        }
        if ($operation === 'delete') {
            if ($t === 'customer_sale_item') {
                $sale = $r->find('customer_sales', $old['SALE_ID']);
                if ($sale['STATUS'] !== 'draft' || self::paid($r, $sale['_ID']) > 0) {
                    throw ValidationException::field($f['SALE_ID'], 'Funded or invoiced sale lines cannot be deleted');
                }
            }
            return [];
        }
        if ($t === 'customer_sale_item') {
            $sale = $r->find('customer_sales', (int)$d['SALE_ID']);
            if (($old && $old['SALE_ID'] !== $d['SALE_ID']) || $sale['STATUS'] !== 'draft' || self::paid($r, $sale['_ID']) > 0) {
                throw ValidationException::field($f['SALE_ID'], 'Sale parent and funded terms are immutable');
            }
            foreach (['SOURCE_PURCHASE_ITEM_ID','SOURCE_LOADING_ID','SOURCE_MOVEMENT_ID'] as $a) {
                if (isset($d[$a]) && $origin !== 'cement.loading') {
                    throw ValidationException::field($f[$a], 'Sale provenance is server-owned');
                }
            }
            $price = $this->price($r, $sale, $d);
            $agreed = $d['AGREED_UNIT_PRICE'] ?? $price['price'] ?? null;
            if (!($r->settings()['allowSalesPriceOverride'] ?? false) && ($price === null || $agreed === null || abs((float)$agreed - (float)$price['price']) > 0.005)) {
                throw ValidationException::field($f['AGREED_UNIT_PRICE'], 'Use an effective price tier; price override is disabled');
            }
            $product = $r->find('product', (int)$d['PRODUCT_ID']);
            if ($product['STATUS'] !== 'active') {
                throw ValidationException::field($f['PRODUCT_ID'], 'Product must be active');
            }
            $factor = $product['BASE_UNIT_ID'] === $d['UNIT_ID'] ? 1 : (($product['PACKAGE_UNIT_ID'] ?? null) === $d['UNIT_ID'] ? ($product['PACKAGE_SIZE'] ?? 0) : 0);
            try {
                $data = SalesMath::line(array_replace($d, ['AGREED_UNIT_PRICE' => $agreed]), (float)$factor);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::field($f['QUANTITY'], $e->getMessage());
            }
            $data += ['AGREED_UNIT_PRICE' => $agreed,'STATUS' => 'draft'];
            if ($price) {
                $data += ['LISTED_UNIT_PRICE' => $price['price'],'PRICE_LIST_ITEM_ID' => $price['id']];
            }return ['proposedData' => $r->encode($t, $data)];
        }
        if ($t === 'customer_sales') {
            $state = $d['STATUS'] ?? 'draft';
            if (!in_array($state, ['draft','invoiced'], true)) {
                throw ValidationException::field($f['STATUS'], 'Invalid sale state');
            }
            if ($old && self::paid($r, $recordId) > 0) {
                foreach (['CUSTOMER_ID','SALE_DATE','WAREHOUSE_ID','CURRENCY','PRICE_LIST_ID'] as $a) {
                    if (($old[$a] ?? null) != ($d[$a] ?? null)) {
                        throw ValidationException::field($f['STATUS'], 'Funded sale terms are immutable');
                    }
                }
            }
            if (isset($d['SOURCE_LOADING_ID']) && $origin !== 'cement.loading' && !$internal) {
                throw ValidationException::field($f['SOURCE_LOADING_ID'], 'Loading provenance is server-owned');
            }
            if ($old && ($old['WAREHOUSE_ID'] ?? null) !== ($d['WAREHOUSE_ID'] ?? null) && NativeReservations::enabled($r)) {
                foreach ($r->rows('inventory_reservation') as $hold) {
                    if ($hold['SALE_ID'] === $recordId && $hold['STATUS'] === 'active') {
                        throw ValidationException::field($f['WAREHOUSE_ID'], 'Release reservations before changing the warehouse');
                    }
                }
            }
            $settings = $r->settings();
            $currency = $settings['defaultCurrency'] ?? 'NGN';
            if (($d['CURRENCY'] ?? $currency) !== $currency) {
                throw ValidationException::field($f['CURRENCY'], 'Sale must use institution currency');
            }
            $policy = $old['OWNERSHIP_TRANSFER_POLICY'] ?? ($record['settings']['cement']['ownershipPolicy'] ?? null) ?? ($settings['ownershipTransferPolicy'] ?? 'loading-transfer');
            if (!in_array($policy, ['loading-transfer','delivery-transfer'], true)) {
                throw ValidationException::field($f['OWNERSHIP_TRANSFER_POLICY'], 'Invalid ownership policy');
            }
            $items = $recordId ? self::items($r, $recordId) : [];
            $gross = 0;
            $net = 0;
            $tax = 0;
            foreach ($items as $line) {
                $gross += (int)round((float)$line['QUANTITY'] * (float)$line['AGREED_UNIT_PRICE'] * 100);
                $net += NativeLedger::minor($line['LINE_AMOUNT']);
                $tax += NativeLedger::minor($line['TAX_AMOUNT'] ?? 0);
            }
            $paid = $recordId ? self::paid($r, $recordId) : 0;
            $advances = $state === 'invoiced' ? NativePayments::invoiceAdvances($r, $recordId, (int)$d['CUSTOMER_ID'], $net) : [];
            $advanceMinor = array_sum(array_column($advances, 'amountMinor'));
            $paid += $advanceMinor;
            $customer = $r->find('customer', (int)$d['CUSTOMER_ID']);
            if ($customer['STATUS'] !== 'active') {
                throw ValidationException::field($f['CUSTOMER_ID'], 'Customer must be active');
            }
            if ($state === 'invoiced') {
                (new NativeLedger())->authorizePosting($context);
                if (!$items || $net <= 0 || $d['SALE_DATE'] > gmdate('Y-m-d')) {
                    throw ValidationException::field($f['STATUS'], 'Invoice requires positive lines and a non-future date');
                }
                if (empty($d['SOURCE_LOADING_ID']) && empty($d['WAREHOUSE_ID'])) {
                    throw ValidationException::field($f['WAREHOUSE_ID'], 'Choose a warehouse for fulfilment');
                }
                $due = max(0, $net - $paid);
                foreach ($r->rows('customer_sales') as $sale) {
                    if ($sale['_ID'] !== $recordId && $sale['CUSTOMER_ID'] === $d['CUSTOMER_ID'] && $sale['STATUS'] === 'invoiced') {
                        $due += NativeReturns::balance($r, $sale);
                    }
                }
                if ((($settings['allowCreditSales'] ?? true) === false && $due > 0) || (($customer['CREDIT_LIMIT'] ?? 0) > 0 && $due > NativeLedger::minor($customer['CREDIT_LIMIT']))) {
                    throw ValidationException::field($f['CUSTOMER_ID'], 'Invoice exceeds the permitted customer credit');
                }
            }
            $proposed = $r->encode($t, ['GROSS_AMOUNT' => NativeLedger::decimal($gross),'DISCOUNT_AMOUNT' => NativeLedger::decimal(max(0, $gross - ($net - $tax))),'TAX_AMOUNT' => NativeLedger::decimal($tax),'NET_AMOUNT' => NativeLedger::decimal($net),'AMOUNT_PAID' => NativeLedger::decimal($paid),'BALANCE_DUE' => NativeLedger::decimal(max(0, $net - $paid)),'CURRENCY' => $currency,'OWNERSHIP_TRANSFER_POLICY' => $policy,'DELIVERY_STATUS' => $old['DELIVERY_STATUS'] ?? 'pending']);
            if ($state === 'invoiced') {
                $proposed['settings'] = ['cement' => array_replace($previous['settings']['cement'] ?? [], ['invoiceAdvanceAllocations' => $advances])];
            }
            if ($state === 'invoiced' && $policy === 'loading-transfer') {
                $snapshot = array_replace($previous['settings']['cement'] ?? [], ['invoiceAdvanceAllocations' => $advances]);
                $snapshot['recognizedMinor'] = $net;
                $snapshot['recognizedTaxMinor'] = $tax;
                $snapshot['appliedDepositsMinor'] = NativePayments::deposits($r, $recordId) + $advanceMinor;
                foreach ($items as $line) {
                    $snapshot['fulfilled'][$line['_ID']] = (float)$line['QUANTITY'];
                }
                $snapshot['appliedDepositsMinor'] = min($net, $snapshot['appliedDepositsMinor']);
                $proposed['settings'] = ['cement' => $snapshot];
            }
            return ['proposedData' => $proposed];
        }
        // Delivery proof controls recognition for delivery-transfer and always advances physical delivery counters.
        $sale = $r->find('customer_sales', (int)$d['SALE_ID']);
        if (!in_array($d['STATUS'] ?? 'draft', ['draft','confirmed'], true)) {
            throw ValidationException::field($f['STATUS'], 'Invalid delivery state');
        }
        if (($d['STATUS'] ?? 'draft') === 'confirmed') {
            (new NativeLedger())->authorizePosting($context);
            if ($sale['STATUS'] !== 'invoiced' || $d['DELIVERED_DATE'] > gmdate('Y-m-d H:i:s')) {
                throw ValidationException::field($f['SALE_ID'], 'Confirm delivery for an invoiced sale on a non-future date');
            }
            $qty = (float)$d['ACCEPTED_QUANTITY'];
            $rejected = (float)($d['REJECTED_QUANTITY'] ?? 0);
            $items = self::items($r, $sale['_ID']);
            $total = array_sum(array_column($items, 'QUANTITY'));
            $accepted = 0;
            $priorRejected = 0;
            foreach ($r->rows('delivery_proof') as $p) {
                if ($p['_ID'] !== $recordId && $p['SALE_ID'] === $sale['_ID'] && $p['STATUS'] === 'confirmed') {
                    $accepted += (float)$p['ACCEPTED_QUANTITY'];
                    $priorRejected += (float)($p['REJECTED_QUANTITY'] ?? 0);
                }
            }
            if (count(array_unique(array_column($items, 'UNIT_ID'))) !== 1 || !is_finite($qty) || $qty < 0 || !is_finite($rejected) || $rejected < 0 || $qty + $rejected <= 0 || abs($qty - round($qty, 6)) > 0.00000001 || abs($rejected - round($rejected, 6)) > 0.00000001 || $accepted + $priorRejected + $qty + $rejected > $total + 0.000001) {
                throw ValidationException::field($f['ACCEPTED_QUANTITY'], 'Delivery exceeds remaining sale quantity or mixes incompatible units');
            }
            if (!empty($d['TRIP_DESTINATION_ID'])) {
                $stop = $r->find('trip_destination', $d['TRIP_DESTINATION_ID']);
                if (($stop['SALE_ID'] ?? null) !== $sale['_ID']) {
                    throw ValidationException::field($f['TRIP_DESTINATION_ID'], 'Delivery destination belongs to another sale');
                }$stopQty = $qty + $rejected;
                foreach ($r->rows('delivery_proof') as $proof) {
                    if ($proof['_ID'] !== $recordId && ($proof['TRIP_DESTINATION_ID'] ?? null) === $stop['_ID'] && $proof['STATUS'] === 'confirmed') {
                        $stopQty += (float)$proof['ACCEPTED_QUANTITY'] + (float)($proof['REJECTED_QUANTITY'] ?? 0);
                    }
                }if ($stopQty > (float)$stop['QUANTITY'] + 0.000001) {
                    throw ValidationException::field($f['ACCEPTED_QUANTITY'], 'Proof exceeds the destination quantity');
                }
            }
            if (empty($d['RECEIVER_NAME']) || empty($d['SIGNATURE'])) {
                throw ValidationException::field($f['SIGNATURE'], 'Receiver name and signature are required');
            }
        }return [];
    }
    private function refresh(NativeRecords $r, array $sale, string $origin, array $extra = [], ?array $private = null): array
    {
        $p = $r->plan('customer_sales', ['CODE' => $sale['CODE']] + $extra, $sale['_ID'], $private);
        $p['_cementGeneratedBy'] = $origin;
        return $p;
    }
    /** Revenue and COGS are recognised together at the institution's snapshotted ownership event. */
    private function recognize(NativeRecords $r, array $sale, float $accepted, string $event, int $eventId, int $virtualDeposits = 0): array
    {
        $items = self::items($r, $sale['_ID']);
        $total = array_sum(array_column($items, 'QUANTITY'));
        $previous = $sale['_PRIVATE'];
        [$net,$tax] = SalesMath::recognition(NativeLedger::minor($sale['NET_AMOUNT']), NativeLedger::minor($sale['TAX_AMOUNT']), $accepted, $total);
        $delta = $net - ($previous['recognizedMinor'] ?? 0);
        $taxDelta = $tax - ($previous['recognizedTaxMinor'] ?? 0);
        if ($delta < 0 || $taxDelta < 0) {
            throw ValidationException::field('quantity', 'Recognized sale value cannot decrease');
        }
        $customer = $r->find('customer', $sale['CUSTOMER_ID']);
        $due = max(0, NativeReturns::balance($r, $sale, $net) - $virtualDeposits);
        foreach ($r->rows('customer_sales') as $other) {
            if ($other['_ID'] !== $sale['_ID'] && $other['CUSTOMER_ID'] === $sale['CUSTOMER_ID']) {
                $due += NativeReturns::balance($r, $other, (int)($other['_PRIVATE']['recognizedMinor'] ?? 0));
            }
        }
        if ((($r->settings()['allowCreditSales'] ?? true) === false && $due > 0) || (($customer['CREDIT_LIMIT'] ?? 0) > 0 && $due > NativeLedger::minor($customer['CREDIT_LIMIT']))) {
            throw ValidationException::field('customer', 'Ownership recognition exceeds permitted customer credit');
        }
        $plans = [];
        $settings = $r->settings();
        $receivable = (int)($r->find('customer', $sale['CUSTOMER_ID'])['ACCOUNT_ID'] ?? $settings['cementReceivablesAccountId'] ?? 0);
        $revenue = (int)($settings['cementSalesRevenueAccountId'] ?? 0);
        $taxGL = (int)($settings['cementTaxPayableAccountId'] ?? 0);
        $costGL = (int)($settings['cementCostOfSalesAccountId'] ?? 0);
        if ($delta > 0) {
            $entries = [[$receivable,$delta,0]];
            if ($delta - $taxDelta > 0) {
                $entries[] = [$revenue,0,$delta - $taxDelta];
            }if ($taxDelta > 0) {
                $entries[] = [$taxGL,0,$taxDelta];
            }$plans = (new NativeLedger())->postingPlans($r->context, 'customer_sales', $sale['_ID'], 'CEMENT-SALE-'.$sale['_ID'].'-'.$event.'-'.$eventId, 'Sale '.$sale['CODE'], $entries);
        }
        $reservationDemand = [];
        foreach ($items as $n => $line) {
            $before = (float)($previous['fulfilled'][$line['_ID']] ?? 0);
            $fulfilled = $accepted + 0.000001 >= $total ? (float)$line['QUANTITY'] : (float)$line['QUANTITY'] * $accepted / $total;
            $qty = round($fulfilled - $before, 6);
            if ($qty <= 0) {
                continue;
            }
            if (!empty($sale['SOURCE_LOADING_ID'])) {
                $cost = SalesMath::portion((int)($line['_PRIVATE']['costMinor'] ?? 0), $fulfilled, (float)$line['QUANTITY']);
                $oldCost = SalesMath::portion((int)($line['_PRIVATE']['costMinor'] ?? 0), $before, (float)$line['QUANTITY']);
                $amount = $cost - $oldCost;
                if ($amount > 0) {
                    $item = $r->find('purchaseItem', $line['SOURCE_PURCHASE_ITEM_ID']);
                    $purchase = $r->find('purchase', $item['PURCHASE_ID']);
                    $plans = [...$plans,...(new NativeLedger())->postingPlans($r->context, 'customer_sale_item', $line['_ID'], 'CEMENT-COGS-'.$line['_ID'].'-'.$event.'-'.$eventId, 'Direct loading cost', [[$costGL,$amount,0],[$purchase['PREPAID_ACCOUNT_ID'],0,$amount]])];
                }
            } else {
                $reservationDemand[$line['PRODUCT_ID']] = ($reservationDemand[$line['PRODUCT_ID']] ?? 0) + $qty * (float)$line['UNIT_CONVERSION_SNAPSHOT']['factor'];
                $p = $r->plan('inventory_movement', ['CODE' => 'SALE-'.$sale['_ID'].'-'.$event.'-'.$eventId.'-'.$n,'MOVEMENT_DATE' => gmdate('Y-m-d H:i:s'),'MOVEMENT_TYPE' => 'sale_issue','PRODUCT_ID' => $line['PRODUCT_ID'],'SOURCE_LOCATION_ID' => $sale['WAREHOUSE_ID'],'STOCK_CONDITION' => 'good','QTY_BASE' => $qty * (float)$line['UNIT_CONVERSION_SNAPSHOT']['factor'],'COST_POLICY' => $settings['inventoryValuationMethod'] ?? 'weighted_average','UNIT_COST' => 0,'SOURCE_RECORD_TYPE_CODE' => 'customer_sale_item','SOURCE_RECORD_ID' => $line['_ID'],'SALE_ITEM_ID' => $line['_ID'],'STATUS' => 'confirmed']);
                $p['_cementGeneratedBy'] = 'cement.sales';
                $plans[] = $p;
            }$previous['fulfilled'][$line['_ID']] = $fulfilled;
        }
        $consumption = [];
        foreach ($reservationDemand as $product => $demand) {
            $consumption = [...$consumption,...NativeReservations::consumePlans($r, $sale['_ID'], $sale['WAREHOUSE_ID'], $product, $demand)];
        }$plans = [...$consumption,...$plans];
        $previous['recognizedMinor'] = $net;
        $previous['recognizedTaxMinor'] = $tax;
        // Payments received before recognition remain deposits until the ownership event.
        $depositPaid = NativePayments::deposits($r, $sale['_ID']) + $virtualDeposits;
        $apply = NativePayments::depositApplication($depositPaid, $net, NativePayments::receivables($r, $sale['_ID']), NativeReturns::totals($r, $sale['_ID'])['receivableMinor']);
        $reclass = $apply - ($previous['appliedDepositsMinor'] ?? 0);
        if ($reclass < 0) {
            throw ValidationException::field('deposit', 'Deposit application cannot reverse a posted allocation');
        }
        if ($reclass > 0) {
            $deposits = (int)($settings['cementCustomerDepositsAccountId'] ?? 0);
            $plans = [...$plans,...(new NativeLedger())->postingPlans($r->context, 'customer_sales', $sale['_ID'], 'CEMENT-DEPOSIT-'.$sale['_ID'].'-'.$event.'-'.$eventId, 'Apply customer deposits', [[$deposits,$reclass,0],[$receivable,0,$reclass]])];
        }$previous['appliedDepositsMinor'] = $apply;
        if ($event !== 'INVOICE') {
            $plans[] = $this->refresh($r, $sale, 'cement.sales.balance', [], $previous);
        }
        return $plans;
    }
    public function effects(string $recordTypeCode, array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $t = $recordTypeCode;
        $d = $r->decode($t, $record);
        $old = $r->decode($t, $previous);
        $origin = TrustedPlans::origin();
        if ($t === 'customer_payment') {
            return NativePayments::effects($record, $recordId, $operation, $context);
        }
        if ($t === 'customer_sale_item') {
            $sale = $r->find('customer_sales', $d['SALE_ID']);
            return ['mutationPlans' => [$this->refresh($r, $sale, 'cement.sales.balance')]];
        }
        if ($operation === 'delete' || in_array($origin, ['cement.sales.balance','cement.payment','cement.delivery','cement.return'], true)) {
            return [];
        }
        if ($t === 'customer_sales') {
            if ($d['STATUS'] !== 'invoiced' || ($old['STATUS'] ?? 'draft') === 'invoiced') {
                return [];
            }$sale = $r->find($t, $recordId);
            $advanceAllocations = $sale['_PRIVATE']['invoiceAdvanceAllocations'] ?? [];
            $advancePlans = NativePayments::advancePlans($r, $recordId, $advanceAllocations);
            $advanceMinor = array_sum(array_column($advanceAllocations, 'amountMinor'));
            $sale['_PRIVATE'] = $previous['settings']['cement'] ?? [];
            if ($d['OWNERSHIP_TRANSFER_POLICY'] === 'loading-transfer') {
                return ['mutationPlans' => [...$advancePlans,...$this->recognize($r, $sale, array_sum(array_column(self::items($r, $recordId), 'QUANTITY')), 'INVOICE', $recordId, $advanceMinor)]];
            }return ['mutationPlans' => $advancePlans];
        }
        if ($t === 'delivery_proof' && $d['STATUS'] === 'confirmed') {
            $sale = $r->find('customer_sales', $d['SALE_ID']);
            $accepted = 0;
            $rejectedTotal = 0;
            foreach ($r->rows('delivery_proof') as $p) {
                if ($p['SALE_ID'] === $sale['_ID'] && $p['STATUS'] === 'confirmed') {
                    $accepted += (float)$p['ACCEPTED_QUANTITY'];
                    $rejectedTotal += (float)($p['REJECTED_QUANTITY'] ?? 0);
                }
            }$total = array_sum(array_column(self::items($r, $sale['_ID']), 'QUANTITY'));
            $plans = $sale['OWNERSHIP_TRANSFER_POLICY'] === 'delivery-transfer' ? $this->recognize($r, $sale, $accepted, 'DELIVERY', $recordId) : [];
            $plans[] = $this->refresh($r, $sale, 'cement.delivery', ['DELIVERY_STATUS' => $accepted + 0.000001 >= $total ? 'delivered' : ($accepted + $rejectedTotal + 0.000001 >= $total ? 'completed_with_rejection' : 'partial'),'DELIVERED_AT' => $d['DELIVERED_DATE']]);
            if (!empty($sale['SOURCE_LOADING_ID'])) {
                $load = $r->find('cement_loading', $sale['SOURCE_LOADING_ID']);
                $item = $r->find('purchaseItem', $load['PURCHASE_ITEM_ID']);
                $p = $r->plan('purchaseItem', ['CODE' => $item['CODE']], $item['_ID']);
                $p['_cementGeneratedBy'] = 'cement.delivery';
                $plans[] = $p;
            }return ['mutationPlans' => $plans];
        }return [];
    }
}
