<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Partial supplier payments and remaining purchase funding, using the shared native ledger. */
final class NativeSupplierPayments
{
    public const TYPES = ['supplier_purchase_payment'];
    public static function enabled(NativeRecords $r): bool
    {
        return isset(M::properties($r->context->catalog->metadata('supplier_purchase_payment'))['settings']['cementSupplierPayments']);
    }
    public static function register($registry): void
    {
        $h = new self();
        $inputs = [['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']];
        foreach (['prepare','effects'] as $method) {
            $registry->register('cement.supplierPayments.'.$method, $h, $method, $inputs);
        }
    }
    public static function definition(array $d): array
    {
        $d['settings']['cementSupplierPayments'] = 1;
        $d['settings']['cementOperations'] = ['read','insert','update','delete'];
        foreach (['prepare' => 'beforeValidate','effects' => 'afterSave'] as $method => $stage) {
            $d['services'][] = ['code' => 'cement_supplier_payments_'.$method,'service' => 'cement.supplierPayments.'.$method,'method' => $method,'stage' => $stage,'trigger' => 'true','order' => $method === 'prepare' ? -1000 : 1000,'parameters' => ['record' => ['source' => 'data'],'previous' => ['source' => 'old'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        }return $d;
    }
    public static function funding(NativeRecords $r, array $purchase): array
    {
        $payments = 0;
        $base = 0;
        foreach ($r->rows('supplier_purchase_payment') as $p) {
            if ($p['PURCHASE_ID'] === $purchase['_ID'] && $p['STATUS'] === 'completed') {
                $payments += NativeLedger::minor($p['AMOUNT']);
            }
        }foreach ($r->rows('purchaseItem') as $line) {
            if ($line['PURCHASE_ID'] === $purchase['_ID']) {
                $base += NativeLedger::minor($line['AMOUNT']);
            }
        }
        $fund = (int)($purchase['_PRIVATE']['fundingMinor'] ?? ($purchase['STATUS'] === 'paid' ? max(0, $base - $payments) : 0));
        return ['fundingMinor' => $fund,'paidMinor' => $fund + $payments];
    }
    public function prepare(array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $d = $r->decode('supplier_purchase_payment', $record);
        $old = $r->decode('supplier_purchase_payment', $previous);
        $f = $r->aliases('supplier_purchase_payment');
        if (($old['STATUS'] ?? null) === 'completed') {
            throw ValidationException::field($f['STATUS'], 'Completed supplier payments are immutable');
        }if ($operation === 'delete') {
            return [];
        }if (!in_array($d['STATUS'] ?? 'draft', ['draft','completed'], true)) {
            throw ValidationException::field($f['STATUS'], 'Invalid supplier payment state');
        }if ($old && $old['PURCHASE_ID'] !== $d['PURCHASE_ID']) {
            throw ValidationException::field($f['PURCHASE_ID'], 'Payment purchase is immutable');
        }$purchase = $r->find('purchase', (int)$d['PURCHASE_ID']);
        $amount = NativeLedger::minor($d['AMOUNT']);
        $paid = self::funding($r, $purchase)['paidMinor'];
        if ($amount <= 0 || $amount > NativeLedger::minor($purchase['NET_AMOUNT']) - $paid) {
            throw ValidationException::field($f['AMOUNT'], 'Payment exceeds the outstanding purchase amount');
        }if (isset($d['JOURNAL_ID'])) {
            throw ValidationException::field($f['JOURNAL_ID'], 'Journal linkage is derived from its authoritative source');
        }if (($d['STATUS'] ?? 'draft') === 'completed') {
            (new NativeLedger())->authorizePosting($context);
            if ($d['PAID_AT'] > gmdate('Y-m-d H:i:s')) {
                throw ValidationException::field($f['PAID_AT'], 'Future payments must remain draft');
            }
        }return [];
    }
    public function effects(array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $d = $r->decode('supplier_purchase_payment', $record);
        if ($operation === 'delete' || $d['STATUS'] !== 'completed') {
            return [];
        }$purchase = $r->find('purchase', $d['PURCHASE_ID']);
        $bank = (int)($purchase['PAYMENT_ACCOUNT_ID'] ?? 0);
        if (!empty($d['BANK_ACCOUNT_ID'])) {
            $account = $r->raw('bank_account', $d['BANK_ACCOUNT_ID']);
            if ($account['status'] !== 'active' || $account['currencyCode'] !== $purchase['CURRENCY']) {
                throw ValidationException::field('bank', 'Bank account must be active in the purchase currency');
            }$bank = (int)$account['glAccountId'];
        }$prepaid = (int)($purchase['PREPAID_ACCOUNT_ID'] ?? 0);
        if ($bank === $prepaid) {
            throw ValidationException::field('accounts', 'Choose different bank and prepaid GL accounts');
        }$minor = NativeLedger::minor($d['AMOUNT']);
        $plans = (new NativeLedger())->postingPlans($context, 'supplier_purchase_payment', $recordId, 'CEMENT-SUPPLIER-PAYMENT-'.$recordId, 'Supplier payment '.$d['CODE'], [[$prepaid,$minor,0],[$bank,0,$minor]]);
        $p = $r->plan('purchase', ['CODE' => $purchase['CODE']], $purchase['_ID']);
        $p['_cementGeneratedBy'] = 'cement.supplier_payment';
        $plans[] = $p;
        return ['mutationPlans' => $plans];
    }
}
