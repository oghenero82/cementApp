<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Database\RecordRepository;
use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Purchase draft arithmetic and parent rollups; never funds or posts journals. */
final class PurchaseDrafts
{
    public static function register($registry): void
    {
        $handler = new self();
        $inputs = [['name' => 'recordTypeCode','type' => 'string'],['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object']];
        $registry->register('cement.purchase.prepare', $handler, 'prepare', $inputs);
        $registry->register('cement.purchase.rollup', $handler, 'rollup', [['name' => 'record','type' => 'object']]);
    }
    public static function definition(array $definition): array
    {
        $type = $definition['code'];
        $definition['settings']['cementOperations'] = ['read','insert','update','delete'];
        $definition['settings']['cementPurchaseDrafts'] = 1;
        $definition['services'][] = ['code' => 'cement_purchase_prepare','service' => 'cement.purchase.prepare','method' => 'prepare','stage' => 'beforeValidate','trigger' => 'true','order' => -1000,'parameters' => ['recordTypeCode' => $type,'record' => ['source' => 'data'],'previous' => ['source' => 'old']]];
        if ($type === 'purchaseItem') {
            $definition['services'][] = ['code' => 'cement_purchase_rollup','service' => 'cement.purchase.rollup','method' => 'rollup','stage' => 'afterSave','trigger' => 'true','order' => 1000,'parameters' => ['record' => ['source' => 'data']]];
        }
        return $definition;
    }
    private function aliases(MutationContext $context, string $type): array
    {
        return M::properties($context->catalog->metadata($type))['settings']['cementFieldAliases'];
    }
    private function decode(MutationContext $context, string $type, array $data): array
    {
        $result = [];
        foreach ($this->aliases($context, $type) as $alias => $code) {
            if (array_key_exists($code, $data)) {
                $result[$alias] = $data[$code];
            }
        }return $result;
    }
    private function encode(MutationContext $context, string $type, array $data): array
    {
        $result = [];
        $aliases = $this->aliases($context, $type);
        foreach ($data as $alias => $value) {
            $result[$aliases[$alias]] = is_float($value) ? \App\Libraries\MetaForge\Catalog\FieldType::numericString($value) : $value;
        }return $result;
    }
    private function find(MutationContext $context, string $type, int $id): array
    {
        $meta = $context->type(M::id($context->catalog->metadata($type)), 'read');
        $repo = new RecordRepository(\Config\Database::connect());
        $row = $repo->find($repo->table($context, $meta), M::id($meta), $id, true);
        if (!$row || M::physical($row, 'isDeleted', 0)) {
            throw ValidationException::field('reference', 'Referenced record is unavailable');
        }
        return $this->decode($context, $type, M::properties($row));
    }
    public function prepare(string $recordTypeCode, array $record, array $previous, MutationContext $context): array
    {
        $d = $this->decode($context, $recordTypeCode, $record);
        $old = $this->decode($context, $recordTypeCode, $previous);
        $funding = $recordTypeCode === 'purchase' && ($d['STATUS'] ?? 'draft') === 'paid' && isset(M::properties($context->catalog->metadata('purchase'))['settings']['cementPurchaseFunding']);
        if ((!$funding && ($d['STATUS'] ?? 'draft') !== 'draft') || ($old && ($old['STATUS'] ?? 'draft') !== 'draft')) {
            throw ValidationException::field('status', 'Only purchase drafts are supported by this native handler; funding is not available');
        }
        if ($recordTypeCode === 'purchase') {
            $settings = M::properties($context->catalog->record('institution', $context->auth['institutionId']))['settings'] ?? [];
            if ($this->find($context, 'supplier', (int)$d['SUPPLIER_ID'])['STATUS'] !== 'active') {
                throw ValidationException::field('supplier', 'Supplier must be active');
            }
            $currency = $settings['defaultCurrency'] ?? 'NGN';
            if (($d['CURRENCY'] ?? $currency) !== $currency) {
                throw ValidationException::field('currency', 'Purchase currency must match the institution currency');
            }
            $policy = $settings['ownershipTransferPolicy'] ?? 'loading-transfer';
            if (!in_array($policy, ['loading-transfer','delivery-transfer'], true)) {
                throw ValidationException::field('ownershipTransferPolicy', 'Invalid institution ownership policy');
            }
            $totals = ['GROSS_AMOUNT' => 0,'DISCOUNT_AMOUNT' => 0,'NET_AMOUNT' => 0,'PAID_AMOUNT' => 0];
            $f = $this->aliases($context, 'purchase');
            $parentCode = $old['CODE'] ?? $d['CODE'] ?? null;
            $meta = $context->type(M::id($context->catalog->metadata('purchase')), 'read');
            $repo = new RecordRepository(\Config\Database::connect());
            $parent = null;
            foreach ($repo->query($repo->table($context, $meta), M::id($meta)) as $row) {
                if (M::property($row, $f['CODE']) === $parentCode) {
                    $parent = M::id($row);
                    break;
                }
            }
            if ($parent) {
                $items = $context->type(M::id($context->catalog->metadata('purchaseItem')), 'read');
                foreach ($repo->query($repo->table($context, $items), M::id($items)) as $row) {
                    $line = $this->decode($context, 'purchaseItem', M::properties($row));
                    if ($line['PURCHASE_ID'] !== $parent) {
                        continue;
                    }$totals['GROSS_AMOUNT'] += $line['ORIGINAL_QUANTITY'] * $line['UNIT_PRICE'];
                    $totals['NET_AMOUNT'] += $line['AMOUNT'];
                }
            }
            $nativeRecords = new NativeRecords($context);
            $partialEnabled = NativeSupplierPayments::enabled($nativeRecords);
            $cash = ['fundingMinor' => 0,'paidMinor' => 0];
            if ($partialEnabled && $parent) {
                $cash = NativeSupplierPayments::funding($nativeRecords, $nativeRecords->find('purchase', $parent));
                if ($cash['paidMinor'] > 0) {
                    foreach (['SUPPLIER_ID','PREPAID_ACCOUNT_ID','CURRENCY'] as $a) {
                        if (($old[$a] ?? null) != ($d[$a] ?? null)) {
                            throw ValidationException::field('purchase', 'Funded purchase identity is immutable');
                        }
                    }
                }$totals['PAID_AMOUNT'] = $cash['paidMinor'] / 100;
            }
            $totals['GROSS_AMOUNT'] = round($totals['GROSS_AMOUNT'], 2);
            $totals['NET_AMOUNT'] = round($totals['NET_AMOUNT'], 2);
            $totals['DISCOUNT_AMOUNT'] = round($totals['GROSS_AMOUNT'] - $totals['NET_AMOUNT'], 2);
            if ($partialEnabled && $cash['paidMinor'] > NativeLedger::minor(\App\Libraries\MetaForge\Catalog\FieldType::numericString($totals['NET_AMOUNT']))) {
                throw ValidationException::field('amount', 'Purchase value cannot fall below paid funding');
            }
            $paid = $funding || ($partialEnabled && $cash['paidMinor'] > 0 && $cash['paidMinor'] === NativeLedger::minor(\App\Libraries\MetaForge\Catalog\FieldType::numericString($totals['NET_AMOUNT'])));
            $encoded = $this->encode($context, 'purchase', $totals + ['STATUS' => $paid ? 'paid' : 'draft','CURRENCY' => $currency,'OWNERSHIP_TRANSFER_POLICY' => $old['OWNERSHIP_TRANSFER_POLICY'] ?? $policy]);
            if ($partialEnabled) {
                $encoded['settings'] = ['cement' => array_replace($previous['settings']['cement'] ?? [], ['fundingMinor' => $cash['fundingMinor']])];
            }return ['proposedData' => $encoded];
        }
        if ($old && ($old['PURCHASE_ID'] ?? null) !== ($d['PURCHASE_ID'] ?? null)) {
            throw ValidationException::field('purchaseId', 'Purchase line parent is immutable');
        }
        $parent = $this->find($context, 'purchase', (int)($d['PURCHASE_ID'] ?? 0));
        if ($parent['STATUS'] === 'draft' && ($parent['PAID_AMOUNT'] ?? 0) > 0) {
            throw ValidationException::field('purchase', 'Partially funded purchase terms are immutable');
        }
        if (($parent['STATUS'] ?? null) !== 'draft') {
            if (!$old || !in_array(TrustedPlans::origin(), ['cement.loading','cement.clearance','cement.delivery','cement.adjustment'], true)) {
                throw ValidationException::field('purchaseId', 'Funded purchase terms are immutable');
            }
            foreach (['CODE','PURCHASE_ID','PRODUCT_ID','UNIT_ID','ORIGINAL_QUANTITY','UNIT_PRICE','DISCOUNT_TYPE','DISCOUNT_VALUE','NET_UNIT_COST','AMOUNT','QUANTITY_BASE','UNIT_CONVERSION_SNAPSHOT'] as $field) {
                if (($old[$field] ?? null) != ($d[$field] ?? null)) {
                    throw ValidationException::field('purchaseId', 'Funded purchase terms are immutable');
                }
            }
            $item = $old + ['_ID' => 0];
            $records = new NativeRecords($context);
            foreach ($records->rows('purchaseItem') as $candidate) {
                if ($candidate['CODE'] === $old['CODE']) {
                    $item = $candidate;
                    break;
                }
            }
            return ['proposedData' => $this->encode($context, 'purchaseItem', NativeLoading::counters(new NativeRecords($context), $item))];
        }
        if (($old['LOADED_QTY'] ?? 0) > 0) {
            throw ValidationException::field('purchaseId', 'Loaded purchase terms are immutable');
        }
        $qty = $d['ORIGINAL_QUANTITY'] ?? 0;
        $price = $d['UNIT_PRICE'] ?? 0;
        $discount = $d['DISCOUNT_VALUE'] ?? 0;
        $kind = $d['DISCOUNT_TYPE'] ?? 'fixed';
        if (!is_numeric($qty) || !is_finite((float)$qty) || $qty <= 0 || !is_numeric($price) || !is_finite((float)$price) || $price < 0 || !is_numeric($discount) || !is_finite((float)$discount) || $discount < 0 || !in_array($kind, ['fixed','percent'], true) || ($kind === 'percent' && $discount > 100)) {
            throw ValidationException::field('quantity', 'Invalid purchase quantity, price or discount');
        }
        $cost = round($kind === 'percent' ? $price * (1 - $discount / 100) : $price - $discount, 2);
        if ($cost < 0) {
            throw ValidationException::field('discount', 'Discount exceeds the unit price');
        }
        $product = $this->find($context, 'product', (int)$d['PRODUCT_ID']);
        if ($product['STATUS'] !== 'active') {
            throw ValidationException::field('product', 'Product must be active');
        }
        $factor = $product['BASE_UNIT_ID'] === $d['UNIT_ID'] ? 1 : (($product['PACKAGE_UNIT_ID'] ?? null) === $d['UNIT_ID'] ? ($product['PACKAGE_SIZE'] ?? 0) : 0);
        if ($factor <= 0) {
            throw ValidationException::field('unitId', 'Declare the product unit conversion first');
        }
        return ['proposedData' => $this->encode($context, 'purchaseItem', ['STATUS' => 'draft','NET_UNIT_COST' => $cost,'AMOUNT' => round($qty * $cost, 2),'QUANTITY_BASE' => $qty * $factor,'UNIT_CONVERSION_SNAPSHOT' => ['factor' => $factor],'LOADED_QTY' => 0,'CLEARED_QTY' => 0,'DELIVERED_QTY' => 0,'FULLY_DELIVERED' => false])];
    }
    public function rollup(array $record, MutationContext $context): array
    {
        $line = $this->decode($context, 'purchaseItem', $record);
        $id = $line['PURCHASE_ID'];
        $parent = $this->find($context, 'purchase', $id);
        if ($parent['STATUS'] !== 'draft') {
            return [];
        }
        $meta = $context->type(M::id($context->catalog->metadata('purchase')), 'update');
        return ['mutationPlans' => [['recordTypeId' => M::id($meta),'recordId' => $id,'operation' => 'update','data' => [$this->aliases($context, 'purchase')['CODE'] => $parent['CODE']]]]];
    }
}
