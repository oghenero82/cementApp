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
        $v = 0;
        foreach ($r->rows('customer_payment') as $p) {
            if (($p['SALE_ID'] ?? null) === $id && $p['STATUS'] === 'completed') {
                $v += NativeLedger::minor($p['AMOUNT']);
            }
        }return $v;
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
        $internal = in_array($origin, ['cement.sales.balance','cement.payment','cement.delivery'], true);
        if ($t === 'customer_payment' && $old && $old['STATUS'] === 'completed' && $origin === 'cement.payment' && $operation === 'update') {
            foreach ($old as $a => $v) {
                if ($a !== 'POSTED_JOURNAL_ID' && ($d[$a] ?? null) != $v) {
                    throw ValidationException::field($f['STATUS'], 'Completed payment terms are immutable');
                }
            }return [];
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
            return ['proposedData' => $r->encode($t, ['AMOUNT_PAID' => NativeLedger::decimal(self::paid($r, $recordId)),'BALANCE_DUE' => NativeLedger::decimal(max(0, NativeLedger::minor($old['NET_AMOUNT']) - self::paid($r, $recordId)))])];
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
                        $due += max(0, NativeLedger::minor($sale['NET_AMOUNT']) - self::paid($r, $sale['_ID']));
                    }
                }
                if ((($settings['allowCreditSales'] ?? true) === false && $due > 0) || (($customer['CREDIT_LIMIT'] ?? 0) > 0 && $due > NativeLedger::minor($customer['CREDIT_LIMIT']))) {
                    throw ValidationException::field($f['CUSTOMER_ID'], 'Invoice exceeds the permitted customer credit');
                }
            }
            $proposed = $r->encode($t, ['GROSS_AMOUNT' => NativeLedger::decimal($gross),'DISCOUNT_AMOUNT' => NativeLedger::decimal(max(0, $gross - ($net - $tax))),'TAX_AMOUNT' => NativeLedger::decimal($tax),'NET_AMOUNT' => NativeLedger::decimal($net),'AMOUNT_PAID' => NativeLedger::decimal($paid),'BALANCE_DUE' => NativeLedger::decimal(max(0, $net - $paid)),'CURRENCY' => $currency,'OWNERSHIP_TRANSFER_POLICY' => $policy,'DELIVERY_STATUS' => $old['DELIVERY_STATUS'] ?? 'pending']);
            if ($state === 'invoiced') {
                $proposed['settings'] = ['cement' => $previous['settings']['cement'] ?? []];
            }
            if ($state === 'invoiced' && $policy === 'loading-transfer') {
                $snapshot = $previous['settings']['cement'] ?? [];
                $snapshot['recognizedMinor'] = $net;
                $snapshot['recognizedTaxMinor'] = $tax;
                $snapshot['appliedDepositsMinor'] = 0;
                foreach ($items as $line) {
                    $snapshot['fulfilled'][$line['_ID']] = (float)$line['QUANTITY'];
                }foreach ($r->rows('customer_payment') as $payment) {
                    if (($payment['SALE_ID'] ?? null) === $recordId && $payment['STATUS'] === 'completed') {
                        $snapshot['appliedDepositsMinor'] += (int)($payment['_PRIVATE']['depositMinor'] ?? 0);
                    }
                }$snapshot['appliedDepositsMinor'] = min($net, $snapshot['appliedDepositsMinor']);
                $proposed['settings'] = ['cement' => $snapshot];
            }
            return ['proposedData' => $proposed];
        }
        if ($t === 'customer_payment') {
            if (!in_array($d['STATUS'] ?? 'draft', ['draft','completed'], true)) {
                throw ValidationException::field($f['STATUS'], 'Invalid payment state');
            }
            if (empty($d['SALE_ID'])) {
                throw ValidationException::field($f['SALE_ID'], 'Select the sale receiving this payment');
            }$sale = $r->find('customer_sales', $d['SALE_ID']);
            if ($sale['CUSTOMER_ID'] !== $d['CUSTOMER_ID']) {
                throw ValidationException::field($f['CUSTOMER_ID'], 'Payment customer must match the sale');
            }
            $amount = NativeLedger::minor($d['AMOUNT']);
            if ($amount <= 0 || $amount > NativeLedger::minor($sale['NET_AMOUNT']) - self::paid($r, $sale['_ID'])) {
                throw ValidationException::field($f['AMOUNT'], 'Payment exceeds the remaining sale amount');
            }
            if (($d['STATUS'] ?? 'draft') === 'completed') {
                (new NativeLedger())->authorizePosting($context);
                if ($d['PAYMENT_DATE'] > gmdate('Y-m-d H:i:s')) {
                    throw ValidationException::field($f['PAYMENT_DATE'], 'Future payments must remain drafts');
                }
            }
            if (isset($d['POSTED_JOURNAL_ID'])) {
                throw ValidationException::field($f['POSTED_JOURNAL_ID'], 'Journal linkage is derived from the authoritative journal source');
            }
            if (($d['STATUS'] ?? 'draft') === 'completed') {
                $recognized = (int)($sale['_PRIVATE']['recognizedMinor'] ?? 0);
                $receivable = min($amount, max(0, $recognized - self::paid($r, $sale['_ID'])));
                return ['proposedData' => ['settings' => ['cement' => ['depositMinor' => $amount - $receivable,'receivableMinor' => $receivable]]]];
            }return [];
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
            foreach ($r->rows('delivery_proof') as $p) {
                if ($p['_ID'] !== $recordId && $p['SALE_ID'] === $sale['_ID'] && $p['STATUS'] === 'confirmed') {
                    $accepted += (float)$p['ACCEPTED_QUANTITY'];
                }
            }
            if (count(array_unique(array_column($items, 'UNIT_ID'))) !== 1 || !is_finite($qty) || $qty <= 0 || !is_finite($rejected) || $rejected < 0 || $accepted + $qty + $rejected > $total + 0.000001) {
                throw ValidationException::field($f['ACCEPTED_QUANTITY'], 'Delivery exceeds remaining sale quantity or mixes incompatible units');
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
    private function recognize(NativeRecords $r, array $sale, float $accepted, string $event, int $eventId): array
    {
        $items = self::items($r, $sale['_ID']);
        $total = array_sum(array_column($items, 'QUANTITY'));
        $previous = $sale['_PRIVATE'];
        $net = SalesMath::portion(NativeLedger::minor($sale['NET_AMOUNT']), $accepted, $total);
        $tax = SalesMath::portion(NativeLedger::minor($sale['TAX_AMOUNT']), $accepted, $total);
        $delta = $net - ($previous['recognizedMinor'] ?? 0);
        $taxDelta = $tax - ($previous['recognizedTaxMinor'] ?? 0);
        if ($delta < 0 || $taxDelta < 0) {
            throw ValidationException::field('quantity', 'Recognized sale value cannot decrease');
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
                $p = $r->plan('inventory_movement', ['CODE' => 'SALE-'.$sale['_ID'].'-'.$event.'-'.$eventId.'-'.$n,'MOVEMENT_DATE' => gmdate('Y-m-d H:i:s'),'MOVEMENT_TYPE' => 'sale_issue','PRODUCT_ID' => $line['PRODUCT_ID'],'SOURCE_LOCATION_ID' => $sale['WAREHOUSE_ID'],'STOCK_CONDITION' => 'good','QTY_BASE' => $qty * (float)$line['UNIT_CONVERSION_SNAPSHOT']['factor'],'COST_POLICY' => $settings['inventoryValuationMethod'] ?? 'weighted_average','UNIT_COST' => 0,'SOURCE_RECORD_TYPE_CODE' => 'customer_sale_item','SOURCE_RECORD_ID' => $line['_ID'],'SALE_ITEM_ID' => $line['_ID'],'STATUS' => 'confirmed']);
                $p['_cementGeneratedBy'] = 'cement.sales';
                $plans[] = $p;
            }$previous['fulfilled'][$line['_ID']] = $fulfilled;
        }
        $previous['recognizedMinor'] = $net;
        $previous['recognizedTaxMinor'] = $tax;
        // Payments received before recognition remain deposits until the ownership event.
        $depositPaid = 0;
        foreach ($r->rows('customer_payment') as $p) {
            if (($p['SALE_ID'] ?? null) === $sale['_ID'] && $p['STATUS'] === 'completed') {
                $depositPaid += (int)($p['_PRIVATE']['depositMinor'] ?? 0);
            }
        }
        $apply = min($depositPaid, $net);
        $reclass = $apply - ($previous['appliedDepositsMinor'] ?? 0);
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
        if ($t === 'customer_sale_item') {
            $sale = $r->find('customer_sales', $d['SALE_ID']);
            return ['mutationPlans' => [$this->refresh($r, $sale, 'cement.sales.balance')]];
        }
        if ($operation === 'delete' || in_array($origin, ['cement.sales.balance','cement.payment','cement.delivery'], true)) {
            return [];
        }
        if ($t === 'customer_sales') {
            if ($d['STATUS'] !== 'invoiced' || ($old['STATUS'] ?? 'draft') === 'invoiced') {
                return [];
            }$sale = $r->find($t, $recordId);
            $sale['_PRIVATE'] = $previous['settings']['cement'] ?? [];
            if ($d['OWNERSHIP_TRANSFER_POLICY'] === 'loading-transfer') {
                return ['mutationPlans' => $this->recognize($r, $sale, array_sum(array_column(self::items($r, $recordId), 'QUANTITY')), 'INVOICE', $recordId)];
            }return [];
        }
        if ($t === 'customer_payment' && $d['STATUS'] === 'completed') {
            $sale = $r->find('customer_sales', $d['SALE_ID']);
            $settings = $r->settings();
            $amount = NativeLedger::minor($d['AMOUNT']);
            $receivedBefore = self::paid($r, $sale['_ID']) - $amount;
            $recognized = (int)($sale['_PRIVATE']['recognizedMinor'] ?? 0);
            $receivableAmount = min($amount, max(0, $recognized - $receivedBefore));
            $deposit = $amount - $receivableAmount;
            $bank = 0;
            if (!empty($d['BANK_ACCOUNT_ID'])) {
                $bank = (int)($r->raw('bank_account', $d['BANK_ACCOUNT_ID'])['glAccountId'] ?? 0);
            }if (!$bank) {
                $bank = (int)($settings['cementReceiptBankAccountId'] ?? 0);
            }$receivable = (int)($r->find('customer', $sale['CUSTOMER_ID'])['ACCOUNT_ID'] ?? $settings['cementReceivablesAccountId'] ?? 0);
            $entries = [[$bank,$amount,0]];
            if ($receivableAmount) {
                $entries[] = [$receivable,0,$receivableAmount];
            }if ($deposit) {
                $entries[] = [(int)($settings['cementCustomerDepositsAccountId'] ?? 0),0,$deposit];
            }
            $plans = (new NativeLedger())->postingPlans($context, 'customer_payment', $recordId, 'CEMENT-RECEIPT-'.$recordId, 'Customer receipt '.$d['CODE'], $entries);
            $plans[] = $this->refresh($r, $sale, 'cement.payment');
            return ['mutationPlans' => $plans];
        }
        if ($t === 'delivery_proof' && $d['STATUS'] === 'confirmed') {
            $sale = $r->find('customer_sales', $d['SALE_ID']);
            $accepted = 0;
            foreach ($r->rows('delivery_proof') as $p) {
                if ($p['SALE_ID'] === $sale['_ID'] && $p['STATUS'] === 'confirmed') {
                    $accepted += (float)$p['ACCEPTED_QUANTITY'];
                }
            }$total = array_sum(array_column(self::items($r, $sale['_ID']), 'QUANTITY'));
            $plans = $sale['OWNERSHIP_TRANSFER_POLICY'] === 'delivery-transfer' ? $this->recognize($r, $sale, $accepted, 'DELIVERY', $recordId) : [];
            $plans[] = $this->refresh($r, $sale, 'cement.delivery', ['DELIVERY_STATUS' => $accepted + 0.000001 >= $total ? 'delivered' : 'partial','DELIVERED_AT' => $d['DELIVERED_DATE']]);
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
