<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Effective supplier schemes, earned receivables and partial cash receipt settlement. */
final class NativeIncentives
{
    public const TYPES = ['supplier_incentive_scheme','supplier_incentive','supplier_incentive_receipt'];
    public static function register($registry): void
    {
        $h = new self();
        $inputs = [['name' => 'recordTypeCode','type' => 'string'],['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']];
        foreach (['prepare','effects'] as $method) {
            $registry->register('cement.incentives.'.$method, $h, $method, $inputs);
        }
    }
    public static function definition(array $d): array
    {
        $d['settings']['cementIncentives'] = 1;
        $d['settings']['cementOperations'] = ['read','insert','update','delete'];
        foreach (['prepare' => 'beforeValidate','effects' => 'afterSave'] as $method => $stage) {
            $d['services'][] = ['code' => 'cement_incentives_'.$method,'service' => 'cement.incentives.'.$method,'method' => $method,'stage' => $stage,'trigger' => 'true','order' => $method === 'prepare' ? -1000 : 1000,'parameters' => ['recordTypeCode' => $d['code'],'record' => ['source' => 'data'],'previous' => ['source' => 'old'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        }return $d;
    }
    private static function received(NativeRecords $r, int $id): int
    {
        $sum = 0;
        foreach ($r->rows('supplier_incentive_receipt') as $row) {
            if ($row['INCENTIVE_ID'] === $id && $row['STATUS'] === 'completed') {
                $sum += NativeLedger::minor($row['AMOUNT']);
            }
        }return $sum;
    }
    private static function ids(NativeRecords $r, mixed $ids, string $type, ?int $supplier = null): array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 500 || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            throw ValidationException::field('scope', 'Use unique record IDs in the scheme scope');
        }foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw ValidationException::field('scope', 'Scheme scope requires record IDs');
            }$row = $r->find($type, $id);
            if ($supplier !== null && $row['SUPPLIER_ID'] !== $supplier) {
                throw ValidationException::field('scope', 'Depot belongs to another supplier');
            }
        }return $ids;
    }
    private static function basis(NativeRecords $r, array $scheme, array $benefit): array
    {
        $products = $scheme['PRODUCT_IDS'] ?? [];
        $depots = $scheme['DEPOT_IDS'] ?? [];
        $qty = 0;
        $minor = 0;
        $linkedPurchase = $benefit['PURCHASE_ID'] ?? null;
        $linkedLoading = $benefit['LOADING_ID'] ?? null;
        $purchases = array_column($r->rows('purchase'), null, '_ID');
        $items = array_column($r->rows('purchaseItem'), null, '_ID');
        $loads = $r->rows('cement_loading');
        if ($linkedLoading) {
            $load = $r->find('cement_loading', $linkedLoading);
            $item = $items[$load['PURCHASE_ITEM_ID']];
            if ($linkedPurchase !== null && $linkedPurchase !== $item['PURCHASE_ID']) {
                throw ValidationException::field('loading', 'Loading belongs to another purchase');
            }$linkedPurchase = $item['PURCHASE_ID'];
        }
        if ($linkedPurchase && (!isset($purchases[$linkedPurchase]) || $purchases[$linkedPurchase]['SUPPLIER_ID'] !== $benefit['SUPPLIER_ID'])) {
            throw ValidationException::field('purchase', 'Purchase belongs to another supplier');
        }
        if (str_starts_with($scheme['BASIS'], 'purchase_')) {
            foreach ($purchases as $p) {
                if ($p['SUPPLIER_ID'] !== $benefit['SUPPLIER_ID'] || $p['STATUS'] !== 'paid' || $p['PURCHASE_DATE'] < $scheme['VALID_FROM'] || $p['PURCHASE_DATE'] > $benefit['BENEFIT_DATE'] || ($depots && !in_array($p['SUPPLIER_DEPOT_ID'] ?? null, $depots, true))) {
                    continue;
                }
                foreach ($items as $item) {
                    if ($item['PURCHASE_ID'] === $p['_ID'] && (!$products || in_array($item['PRODUCT_ID'], $products, true))) {
                        [$q,$value] = NativeLoading::entitlement($r, $item);
                        $qty += $q * (float)$item['UNIT_CONVERSION_SNAPSHOT']['factor'];
                        $minor += $value;
                    }
                }
            }
        } else {
            foreach ($loads as $load) {
                $item = $items[$load['PURCHASE_ITEM_ID']] ?? null;
                $p = $item ? $purchases[$item['PURCHASE_ID']] ?? null : null;
                $date = substr($load['LOADING_DATE'], 0, 10);
                if (!$p || $p['SUPPLIER_ID'] !== $benefit['SUPPLIER_ID'] || $load['STATUS'] !== 'confirmed' || $date < $scheme['VALID_FROM'] || $date > $benefit['BENEFIT_DATE'] || ($products && !in_array($item['PRODUCT_ID'], $products, true)) || ($depots && !in_array($load['SUPPLIER_DEPOT_ID'], $depots, true))) {
                    continue;
                }$qty += (float)$load['QUANTITY_BASE'];
                $minor += (int)($load['_PRIVATE']['costMinor'] ?? 0);
            }
        }
        return [round($qty, 6),$minor];
    }
    public function prepare(string $recordTypeCode, array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $t = $recordTypeCode;
        $d = $r->decode($t, $record);
        $old = $r->decode($t, $previous);
        $f = $r->aliases($t);
        if ($t === 'supplier_incentive_scheme') {
            if ($old) {
                foreach ($r->rows('supplier_incentive') as $benefit) {
                    if (($benefit['SCHEME_ID'] ?? null) === $recordId && $benefit['STATUS'] === 'verified') {
                        if ($operation === 'delete') {
                            throw ValidationException::field($f['STATUS'], 'Earned schemes retain their history');
                        }foreach ($old as $a => $v) {
                            if ($a !== 'STATUS' && ($d[$a] ?? null) != $v) {
                                throw ValidationException::field($f['STATUS'], 'Earned scheme terms are immutable; create a new scheme');
                            }
                        }
                    }
                }
            }
            if ($operation === 'delete') {
                return [];
            }if (!in_array($d['STATUS'] ?? 'active', ['active','inactive'], true) || !in_array($d['BASIS'], ['purchase_quantity','purchase_amount','loading_quantity','loading_amount'], true) || !in_array($d['CALCULATION_TYPE'], ['percent','per_quantity','fixed'], true)) {
                throw ValidationException::field($f['BASIS'], 'Choose a supported basis and calculation');
            }if (($d['CALCULATION_TYPE'] === 'percent' && !str_ends_with($d['BASIS'], '_amount')) || ($d['CALCULATION_TYPE'] === 'per_quantity' && !str_ends_with($d['BASIS'], '_quantity'))) {
                throw ValidationException::field($f['CALCULATION_TYPE'], 'Percent uses an amount basis; per_quantity uses quantity');
            }
            if (!empty($d['VALID_TO']) && $d['VALID_TO'] < $d['VALID_FROM']) {
                throw ValidationException::field($f['VALID_TO'], 'End date must follow start date');
            }self::ids($r, $d['PRODUCT_IDS'] ?? [], 'product');
            self::ids($r, $d['DEPOT_IDS'] ?? [], 'supplier_depot', (int)$d['SUPPLIER_ID']);
            $threshold = (float)($d['THRESHOLD_QTY'] ?? 0);
            if (!is_finite($threshold) || $threshold < 0 || abs($threshold - round($threshold, 6)) > 0.00000001) {
                throw ValidationException::field($f['THRESHOLD_QTY'], 'Use a nonnegative threshold with at most six decimals');
            }NativeLedger::minor($d['THRESHOLD_AMOUNT'] ?? 0);
            BenefitMath::amount(0, 0, $d['CALCULATION_TYPE'], (float)($d['RATE'] ?? 0), NativeLedger::minor($d['FIXED_AMOUNT'] ?? 0));
            return [];
        }
        $final = $t === 'supplier_incentive' ? 'verified' : 'completed';
        if (($old['STATUS'] ?? null) === $final) {
            if ($t === 'supplier_incentive' && TrustedPlans::origin() === 'cement.incentive_receipt' && $operation === 'update') {
                foreach ($old as $a => $v) {
                    if ($a !== 'AMOUNT_RECEIVED' && ($d[$a] ?? null) != $v) {
                        throw ValidationException::field($f['STATUS'], 'Verified benefit terms are immutable');
                    }
                }$sum = self::received($r, $recordId);
                if ($sum > NativeLedger::minor($old['ACTUAL_AMOUNT'])) {
                    throw ValidationException::field($f['AMOUNT_RECEIVED'], 'Receipts exceed earned benefit');
                }return ['proposedData' => $r->encode($t, ['AMOUNT_RECEIVED' => NativeLedger::decimal($sum)])];
            }
            throw ValidationException::field($f['STATUS'], 'Verified benefits and completed receipts are immutable');
        }
        if ($operation === 'delete') {
            return [];
        }if (!in_array($d['STATUS'] ?? 'draft', ['draft',$final], true)) {
            throw ValidationException::field($f['STATUS'], 'Invalid incentive state');
        }$posting = ($d['STATUS'] ?? 'draft') === $final;
        if ($t === 'supplier_incentive_receipt') {
            $benefit = $r->find('supplier_incentive', (int)$d['INCENTIVE_ID']);
            if ($old && $old['INCENTIVE_ID'] !== $d['INCENTIVE_ID']) {
                throw ValidationException::field($f['INCENTIVE_ID'], 'Receipt benefit is immutable');
            }$amount = NativeLedger::minor($d['AMOUNT']);
            if ($benefit['STATUS'] !== 'verified' || $amount <= 0 || $amount > NativeLedger::minor($benefit['ACTUAL_AMOUNT']) - self::received($r, $benefit['_ID'])) {
                throw ValidationException::field($f['AMOUNT'], 'Receive only the verified outstanding benefit');
            }if (isset($d['JOURNAL_ID'])) {
                throw ValidationException::field($f['JOURNAL_ID'], 'Journal linkage is derived from its source');
            }if ($posting) {
                (new NativeLedger())->authorizePosting($context);
                if ($d['RECEIVED_DATE'] > gmdate('Y-m-d')) {
                    throw ValidationException::field($f['RECEIVED_DATE'], 'Future receipts must remain draft');
                }
            }return [];
        }
        if (isset($d['JOURNAL_ID'])) {
            throw ValidationException::field($f['JOURNAL_ID'], 'Journal linkage is derived from its source');
        }if (!in_array($d['BENEFIT_TYPE'], ['cash','rebate','credit_note','manual'], true)) {
            throw ValidationException::field($f['BENEFIT_TYPE'], 'Choose cash, rebate, credit_note or manual');
        }$amount = NativeLedger::minor($d['ACTUAL_AMOUNT'] ?? 0);
        $data = ['AMOUNT_RECEIVED' => '0.00'];
        $private = [];
        if (!empty($d['PURCHASE_ID'])) {
            $p = $r->find('purchase', $d['PURCHASE_ID']);
            if ($p['SUPPLIER_ID'] !== $d['SUPPLIER_ID']) {
                throw ValidationException::field($f['PURCHASE_ID'], 'Purchase belongs to another supplier');
            }
        }if (!empty($d['LOADING_ID'])) {
            $load = $r->find('cement_loading', $d['LOADING_ID']);
            $item = $r->find('purchaseItem', $load['PURCHASE_ITEM_ID']);
            $p = $r->find('purchase', $item['PURCHASE_ID']);
            if ($load['STATUS'] !== 'confirmed' || $p['SUPPLIER_ID'] !== $d['SUPPLIER_ID'] || (!empty($d['PURCHASE_ID']) && $d['PURCHASE_ID'] !== $p['_ID'])) {
                throw ValidationException::field($f['LOADING_ID'], 'Loading must match this supplier and purchase');
            }
        }
        if (!empty($d['SCHEME_ID'])) {
            $scheme = $r->find('supplier_incentive_scheme', $d['SCHEME_ID']);
            if (!($r->settings()['enableIncentiveSchemes'] ?? false)) {
                throw ValidationException::field($f['SCHEME_ID'], 'Institution incentive schemes are disabled');
            }if ($scheme['STATUS'] !== 'active' || $scheme['SUPPLIER_ID'] !== $d['SUPPLIER_ID'] || $d['BENEFIT_DATE'] < $scheme['VALID_FROM'] || (!empty($scheme['VALID_TO']) && $d['BENEFIT_DATE'] > $scheme['VALID_TO'])) {
                throw ValidationException::field($f['SCHEME_ID'], 'Scheme is not effective for this supplier/date');
            }[$q,$basis] = self::basis($r, $scheme, $d);
            $met = $q >= (float)($scheme['THRESHOLD_QTY'] ?? 0) && $basis >= NativeLedger::minor($scheme['THRESHOLD_AMOUNT'] ?? 0);
            $cumulative = $met ? BenefitMath::amount($q, $basis, $scheme['CALCULATION_TYPE'], (float)($scheme['RATE'] ?? 0), NativeLedger::minor($scheme['FIXED_AMOUNT'] ?? 0)) : 0;
            $priorEarned = 0;
            foreach ($r->rows('supplier_incentive') as $prior) {
                if ($prior['_ID'] !== $recordId && ($prior['SCHEME_ID'] ?? null) === $scheme['_ID'] && $prior['STATUS'] === 'verified') {
                    $priorEarned += NativeLedger::minor($prior['ACTUAL_AMOUNT']);
                }
            }$amount = max(0, $cumulative - $priorEarned);
            $private['cumulativeEntitlementMinor'] = $cumulative;
            $private['priorEarnedMinor'] = $priorEarned;
            if ($q <= 0 && $basis <= 0) {
                $amount = 0;
                if ($posting) {
                    throw ValidationException::field($f['SCHEME_ID'], 'Scheme has no qualifying purchases or loadings');
                }
            }if ($posting && !$met) {
                throw ValidationException::field($f['SCHEME_ID'], 'Scheme threshold has not been met');
            }$data += ['BASIS_QUANTITY' => $q,'BASIS_AMOUNT' => NativeLedger::decimal($basis),'RATE' => $scheme['RATE'] ?? 0];
            $private['schemeSnapshot'] = array_diff_key($scheme, ['_ID' => true,'_MUTATION' => true,'_PRIVATE' => true]);
        }
        $data['ACTUAL_AMOUNT'] = NativeLedger::decimal($amount);
        if ($posting) {
            (new NativeLedger())->authorizePosting($context);
            if ($amount <= 0 || $d['BENEFIT_DATE'] > gmdate('Y-m-d')) {
                throw ValidationException::field($f['ACTUAL_AMOUNT'], 'Verify a positive earned benefit on a non-future date');
            }$data['RECEIVABLE_ACCOUNT_ID'] = $d['RECEIVABLE_ACCOUNT_ID'] ?? $r->settings()['cementIncentiveReceivableAccountId'] ?? 0;
            $data['INCOME_ACCOUNT_ID'] = $d['INCOME_ACCOUNT_ID'] ?? $r->settings()['cementIncentiveIncomeAccountId'] ?? 0;
            if ($data['RECEIVABLE_ACCOUNT_ID'] <= 0 || $data['INCOME_ACCOUNT_ID'] <= 0 || $data['RECEIVABLE_ACCOUNT_ID'] === $data['INCOME_ACCOUNT_ID']) {
                throw ValidationException::field('accounts', 'Choose different receivable and income accounts');
            }$private['currency'] = $r->settings()['defaultCurrency'] ?? 'NGN';
        }
        $encoded = $r->encode($t, $data);
        $encoded['settings'] = ['cement' => $private];
        return ['proposedData' => $encoded];
    }
    public function effects(string $recordTypeCode, array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        if ($operation === 'delete') {
            return [];
        }$r = new NativeRecords($context);
        $d = $r->decode($recordTypeCode, $record);
        if ($recordTypeCode === 'supplier_incentive' && $d['STATUS'] === 'verified' && TrustedPlans::origin() !== 'cement.incentive_receipt') {
            $amount = NativeLedger::minor($d['ACTUAL_AMOUNT']);
            return ['mutationPlans' => (new NativeLedger())->postingPlans($context, 'supplier_incentive', $recordId, 'CEMENT-INCENTIVE-'.$recordId, 'Earned supplier benefit '.$d['CODE'], [[$d['RECEIVABLE_ACCOUNT_ID'],$amount,0],[$d['INCOME_ACCOUNT_ID'],0,$amount]])];
        }
        if ($recordTypeCode === 'supplier_incentive_receipt' && $d['STATUS'] === 'completed') {
            $benefit = $r->find('supplier_incentive', $d['INCENTIVE_ID']);
            $bank = (int)($r->settings()['cementIncentiveBankAccountId'] ?? $r->settings()['cementReceiptBankAccountId'] ?? 0);
            if (!empty($d['BANK_ACCOUNT_ID'])) {
                $account = $r->raw('bank_account', $d['BANK_ACCOUNT_ID']);
                if ($account['status'] !== 'active' || $account['currencyCode'] !== ($benefit['_PRIVATE']['currency'] ?? 'NGN')) {
                    throw ValidationException::field('bank', 'Bank account must be active in the earned currency');
                }$bank = $account['glAccountId'];
            }$amount = NativeLedger::minor($d['AMOUNT']);
            if ($bank === $benefit['RECEIVABLE_ACCOUNT_ID']) {
                throw ValidationException::field('accounts', 'Bank and receivable accounts must differ');
            }$plans = (new NativeLedger())->postingPlans($context, 'supplier_incentive_receipt', $recordId, 'CEMENT-INCENTIVE-RECEIPT-'.$recordId, 'Supplier benefit receipt '.$d['CODE'], [[$bank,$amount,0],[$benefit['RECEIVABLE_ACCOUNT_ID'],0,$amount]]);
            $p = $r->plan('supplier_incentive', ['CODE' => $benefit['CODE']], $benefit['_ID']);
            $p['_cementGeneratedBy'] = 'cement.incentive_receipt';
            $plans[] = $p;
            return ['mutationPlans' => $plans];
        }return [];
    }
}
