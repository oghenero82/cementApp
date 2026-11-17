<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Credits, cash refunds and original-cost return receipts in one native transaction. */
final class NativeReturns
{
    public const TYPES = ['customer_sale_return'];
    public static function register($registry): void
    {
        $h = new self();
        $inputs = [['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']];
        foreach (['prepare','effects'] as $method) {
            $registry->register('cement.returns.'.$method, $h, $method, $inputs);
        }
    }
    public static function definition(array $d): array
    {
        $d['settings']['cementReturns'] = 1;
        $d['settings']['cementOperations'] = ['read','insert','update','delete'];
        foreach (['prepare' => 'beforeValidate','effects' => 'afterSave'] as $method => $stage) {
            $d['services'][] = ['code' => 'cement_returns_'.$method,'service' => 'cement.returns.'.$method,'method' => $method,'stage' => $stage,'trigger' => 'true','order' => $method === 'prepare' ? -1000 : 1000,'parameters' => ['record' => ['source' => 'data'],'previous' => ['source' => 'old'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        }return $d;
    }
    public static function totals(NativeRecords $r, int $sale): array
    {
        $sum = ['creditMinor' => 0,'refundMinor' => 0,'receivableMinor' => 0];
        if (!isset(M::properties($r->context->catalog->metadata('customer_sale_return'))['settings']['cementReturns'])) {
            return $sum;
        }
        foreach ($r->rows('customer_sale_return') as $ret) {
            if ($ret['SALE_ID'] === $sale && $ret['STATUS'] === 'confirmed') {
                foreach ($sum as $key => $value) {
                    $sum[$key] += (int)($ret['_PRIVATE'][$key] ?? 0);
                }
            }
        }return $sum;
    }
    public static function balance(NativeRecords $r, array $sale, ?int $gross = null): int
    {
        $totals = self::totals($r, $sale['_ID']);
        return max(0, ($gross ?? NativeLedger::minor($sale['NET_AMOUNT'])) - NativePayments::paid($r, $sale['_ID']) - $totals['creditMinor'] + $totals['refundMinor']);
    }
    public function prepare(array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $d = $r->decode('customer_sale_return', $record);
        $old = $r->decode('customer_sale_return', $previous);
        $f = $r->aliases('customer_sale_return');
        if (($old['STATUS'] ?? null) === 'confirmed') {
            throw ValidationException::field($f['STATUS'], 'Confirmed returns are immutable');
        }if ($operation === 'delete') {
            return [];
        }if (!in_array($d['STATUS'] ?? 'draft', ['draft','confirmed'], true)) {
            throw ValidationException::field($f['STATUS'], 'Invalid return state');
        }
        $line = $r->find('customer_sale_item', (int)$d['SALE_ITEM_ID']);
        $sale = $r->find('customer_sales', (int)$d['SALE_ID']);
        if ($line['SALE_ID'] !== $sale['_ID'] || $d['UNIT_ID'] !== $line['UNIT_ID']) {
            throw ValidationException::field($f['SALE_ITEM_ID'], 'Return must match its sale line and snapshotted unit');
        }$qty = (float)$d['QUANTITY'];
        if (!is_finite($qty) || $qty <= 0 || abs($qty - round($qty, 6)) > 0.00000001) {
            throw ValidationException::field($f['QUANTITY'], 'Use a positive quantity with at most six decimals');
        }if (!in_array($d['DISPOSITION'], ['restock','damaged','discard'], true)) {
            throw ValidationException::field($f['DISPOSITION'], 'Choose restock, damaged or discard');
        }
        if (($d['STATUS'] ?? 'draft') === 'draft') {
            return ['proposedData' => $r->encode('customer_sale_return', ['REFUND_AMOUNT' => '0.00'])];
        }(new NativeLedger())->authorizePosting($context);
        if ($sale['STATUS'] !== 'invoiced') {
            throw ValidationException::field($f['SALE_ID'], 'Return requires an invoiced sale');
        }$fulfilled = (float)($sale['_PRIVATE']['fulfilled'][$line['_ID']] ?? 0);
        $returned = 0;
        $credited = 0;
        $taxCredited = 0;
        $costReturned = 0;
        foreach ($r->rows('customer_sale_return') as $ret) {
            if ($ret['_ID'] !== $recordId && $ret['SALE_ITEM_ID'] === $line['_ID'] && $ret['STATUS'] === 'confirmed') {
                $returned += (float)$ret['QUANTITY'];
                $credited += (int)$ret['_PRIVATE']['creditMinor'];
                $taxCredited += (int)$ret['_PRIVATE']['taxMinor'];
                $costReturned += (int)$ret['_PRIVATE']['costMinor'];
            }
        }
        if ($returned + $qty > $fulfilled + 0.000001) {
            throw ValidationException::field($f['QUANTITY'], 'Return exceeds transferred sale quantity');
        }[$credit,$tax] = SalesMath::recognition(NativeLedger::minor($line['LINE_AMOUNT']), NativeLedger::minor($line['TAX_AMOUNT']), $returned + $qty, (float)$line['QUANTITY']);
        $credit -= $credited;
        $tax -= $taxCredited;
        $prior = self::totals($r, $sale['_ID']);
        if ($credit <= 0 || $credit > (int)($sale['_PRIVATE']['recognizedMinor'] ?? 0) - $prior['creditMinor']) {
            throw ValidationException::field($f['QUANTITY'], 'Return exceeds recognized sale value');
        }$receivable = min($credit, self::balance($r, $sale, (int)($sale['_PRIVATE']['recognizedMinor'] ?? 0)));
        $refund = $credit - $receivable;
        $cost = 0;
        if (!empty($line['SOURCE_LOADING_ID'])) {
            $cost = SalesMath::portion((int)($line['_PRIVATE']['costMinor'] ?? 0), $fulfilled, (float)$line['QUANTITY']);
        } else {
            foreach ($r->rows('inventory_movement') as $m) {
                if (($m['SALE_ITEM_ID'] ?? null) === $line['_ID'] && $m['STATUS'] === 'confirmed' && !empty($m['SOURCE_LOCATION_ID']) && ($m['SOURCE_RECORD_TYPE_CODE'] ?? null) === 'customer_sale_item') {
                    $cost += NativeLedger::minor($m['TOTAL_COST']);
                }
            }
        }$returnCost = SalesMath::portion($cost, $returned + $qty, $fulfilled) - $costReturned;
        if ($returnCost < 0 || $tax < 0 || $tax > $credit) {
            throw ValidationException::field($f['QUANTITY'], 'Return snapshots are inconsistent');
        }
        $location = $sale['WAREHOUSE_ID'] ?? null;
        if (in_array($d['DISPOSITION'], ['restock','damaged'], true)) {
            if (!empty($d['STOCK_MOVEMENT_ID'])) {
                $m = $r->find('inventory_movement', $d['STOCK_MOVEMENT_ID']);
                if ($m['STATUS'] !== 'draft' || !empty($m['SOURCE_LOCATION_ID']) || $m['PRODUCT_ID'] !== $line['PRODUCT_ID'] || abs((float)$m['QTY_BASE'] - $qty * (float)$line['UNIT_CONVERSION_SNAPSHOT']['factor']) > 0.000001 || empty($m['DESTINATION_LOCATION_ID'])) {
                    throw ValidationException::field($f['STOCK_MOVEMENT_ID'], 'Select a matching draft return receipt');
                }$location = $m['DESTINATION_LOCATION_ID'];
            }
            if (!$location) {
                throw ValidationException::field($f['STOCK_MOVEMENT_ID'], 'Choose a draft receipt with the return warehouse');
            }if ($r->find('inventory_location', $location)['STATUS'] !== 'active') {
                throw ValidationException::field($f['STOCK_MOVEMENT_ID'], 'Return location must be active');
            }
        } elseif (!empty($d['STOCK_MOVEMENT_ID'])) {
            throw ValidationException::field($f['STOCK_MOVEMENT_ID'], 'Discarded returns do not receive stock');
        }
        $data = ['REFUND_AMOUNT' => NativeLedger::decimal($refund)];
        $person = $r->actorPerson();
        if ($person !== null) {
            $data['APPROVED_BY'] = $person;
        } elseif (isset($d['APPROVED_BY'])) {
            throw ValidationException::field($f['APPROVED_BY'], 'Link the approving user to a staff person');
        }$encoded = $r->encode('customer_sale_return', $data);
        $encoded['settings'] = ['cement' => ['creditMinor' => $credit,'taxMinor' => $tax,'receivableMinor' => $receivable,'refundMinor' => $refund,'costMinor' => $returnCost,'locationId' => $location]];
        return ['proposedData' => $encoded];
    }
    public function effects(array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $d = $r->decode('customer_sale_return', $record);
        if ($operation === 'delete' || $d['STATUS'] !== 'confirmed') {
            return [];
        }$v = $record['settings']['cement'];
        $sale = $r->find('customer_sales', $d['SALE_ID']);
        $line = $r->find('customer_sale_item', $d['SALE_ITEM_ID']);
        $settings = $r->settings();
        $receivable = (int)($r->find('customer', $sale['CUSTOMER_ID'])['ACCOUNT_ID'] ?? $settings['cementReceivablesAccountId'] ?? 0);
        $revenue = (int)($settings['cementSalesReturnsAccountId'] ?? $settings['cementSalesRevenueAccountId'] ?? 0);
        $tax = (int)($settings['cementTaxPayableAccountId'] ?? 0);
        $bank = (int)($settings['cementRefundBankAccountId'] ?? $settings['cementReceiptBankAccountId'] ?? 0);
        $entries = [];
        if ($v['creditMinor'] - $v['taxMinor'] > 0) {
            $entries[] = [$revenue,$v['creditMinor'] - $v['taxMinor'],0];
        }if ($v['taxMinor']) {
            $entries[] = [$tax,$v['taxMinor'],0];
        }if ($v['receivableMinor']) {
            $entries[] = [$receivable,0,$v['receivableMinor']];
        }if ($v['refundMinor']) {
            $entries[] = [$bank,0,$v['refundMinor']];
        }$plans = (new NativeLedger())->postingPlans($context, 'customer_sale_return', $recordId, 'CEMENT-RETURN-'.$recordId, 'Customer return '.$d['CODE'], $entries);
        if (in_array($d['DISPOSITION'], ['restock','damaged'], true)) {
            $base = (float)$d['QUANTITY'] * (float)$line['UNIT_CONVERSION_SNAPSHOT']['factor'];
            $data = ['CODE' => 'RETURN-'.$recordId,'MOVEMENT_DATE' => gmdate('Y-m-d H:i:s'),'MOVEMENT_TYPE' => 'customer_return','PRODUCT_ID' => $line['PRODUCT_ID'],'DESTINATION_LOCATION_ID' => $v['locationId'],'STOCK_CONDITION' => $d['DISPOSITION'] === 'damaged' ? 'damaged' : 'good','QTY_BASE' => $base,'UNIT_COST' => $v['costMinor'] / 100 / $base,'COST_POLICY' => $settings['inventoryValuationMethod'] ?? 'weighted_average','SOURCE_RECORD_TYPE_CODE' => 'customer_sale_return','SOURCE_RECORD_ID' => $recordId,'SALE_ITEM_ID' => $line['_ID'],'STATUS' => 'confirmed'];
            $p = $r->plan('inventory_movement', $data, $d['STOCK_MOVEMENT_ID'] ?? null);
            $p['_cementGeneratedBy'] = 'cement.return';
            $plans[] = $p;
        }
        $p = $r->plan('customer_sales', ['CODE' => $sale['CODE']], $sale['_ID']);
        $p['_cementGeneratedBy'] = 'cement.return';
        $plans[] = $p;
        return ['mutationPlans' => $plans];
    }
}
