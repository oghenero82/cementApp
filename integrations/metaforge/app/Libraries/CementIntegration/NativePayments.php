<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Immutable receipt allocation history, customer advances and FIFO application to new invoices. */
final class NativePayments
{
    public static function depositApplication(int $deposits, int $recognized, int $receivableCredits, int $returnCredits): int
    {
        if (min($deposits, $recognized, $receivableCredits, $returnCredits) < 0) {
            throw new \InvalidArgumentException('Invalid deposit application');
        }return min($deposits, max(0, $recognized - $receivableCredits - $returnCredits));
    }
    public static function allocations(array $p): array
    {
        if (isset($p['_PRIVATE']['allocations'])) {
            return $p['_PRIVATE']['allocations'];
        }
        if (empty($p['SALE_ID'])) {
            return [];
        }
        return [['saleId' => $p['SALE_ID'],'amountMinor' => NativeLedger::minor($p['AMOUNT']),'receivableMinor' => (int)($p['_PRIVATE']['receivableMinor'] ?? 0),'depositMinor' => (int)($p['_PRIVATE']['depositMinor'] ?? 0)]];
    }
    public static function paid(NativeRecords $r, int $sale): int
    {
        $sum = 0;
        foreach ($r->rows('customer_payment') as $p) {
            if ($p['STATUS'] === 'completed') {
                foreach (self::allocations($p) as $a) {
                    if ($a['saleId'] === $sale) {
                        $sum += $a['amountMinor'];
                    }
                }
            }
        }return $sum;
    }
    public static function receivables(NativeRecords $r, int $sale): int
    {
        $sum = 0;
        foreach ($r->rows('customer_payment') as $p) {
            if ($p['STATUS'] === 'completed') {
                foreach (self::allocations($p) as $a) {
                    if ($a['saleId'] === $sale) {
                        $sum += $a['receivableMinor'];
                    }
                }
            }
        }return $sum;
    }
    public static function deposits(NativeRecords $r, int $sale): int
    {
        $sum = 0;
        foreach ($r->rows('customer_payment') as $p) {
            if ($p['STATUS'] === 'completed') {
                foreach (self::allocations($p) as $a) {
                    if ($a['saleId'] === $sale) {
                        $sum += $a['depositMinor'];
                    }
                }
            }
        }return $sum;
    }
    public static function invoiceAdvances(NativeRecords $r, int $sale, int $customer, int $total): array
    {
        $remaining = NativeReturns::balance($r, $r->find('customer_sales', $sale), $total);
        $payments = array_values(array_filter($r->rows('customer_payment'), fn ($p) => $p['STATUS'] === 'completed' && $p['CUSTOMER_ID'] === $customer && ($p['_PRIVATE']['unallocatedMinor'] ?? 0) > 0));
        usort($payments, fn ($a, $b) => [$a['PAYMENT_DATE'],$a['_ID']] <=> [$b['PAYMENT_DATE'],$b['_ID']]);
        $result = [];
        foreach ($payments as $p) {
            $used = min($remaining, (int)$p['_PRIVATE']['unallocatedMinor']);
            if (!$used) {
                break;
            }$result[] = ['paymentId' => $p['_ID'],'amountMinor' => $used];
            $remaining -= $used;
        }return $result;
    }
    public static function advancePlans(NativeRecords $r, int $sale, array $allocations): array
    {
        $plans = [];
        foreach ($allocations as $a) {
            $p = $r->find('customer_payment', $a['paymentId']);
            $private = $p['_PRIVATE'];
            $private['allocations'][] = ['saleId' => $sale,'amountMinor' => $a['amountMinor'],'receivableMinor' => 0,'depositMinor' => $a['amountMinor']];
            $private['unallocatedMinor'] -= $a['amountMinor'];
            $plan = $r->plan('customer_payment', ['CODE' => $p['CODE']], $p['_ID'], $private);
            $plan['_cementGeneratedBy'] = 'cement.advance';
            $plans[] = $plan;
        }return $plans;
    }
    public static function prepare(array $record, array $previous, int $id, string $operation, MutationContext $ctx): array
    {
        $r = new NativeRecords($ctx);
        $d = $r->decode('customer_payment', $record);
        $old = $r->decode('customer_payment', $previous);
        $f = $r->aliases('customer_payment');
        $state = $d['STATUS'] ?? 'draft';
        if (($old['STATUS'] ?? null) === 'completed') {
            if (TrustedPlans::origin() !== 'cement.advance' || $operation !== 'update') {
                throw ValidationException::field($f['STATUS'], 'Completed receipts are immutable');
            }foreach ($old as $a => $v) {
                if (($d[$a] ?? null) != $v) {
                    throw ValidationException::field($f['STATUS'], 'Receipt terms are immutable');
                }
            }
            $before = $previous['settings']['cement'] ?? [];
            $after = $record['settings']['cement'] ?? [];
            $expected = $before;
            $entries = $after['allocations'] ?? [];
            $prior = $before['allocations'] ?? [];
            if (count($entries) !== count($prior) + 1 || array_slice($entries, 0, count($prior)) !== $prior) {
                throw ValidationException::field('allocation', 'Receipt allocation history is immutable');
            }$last = end($entries);
            $amount = $last['amountMinor'] ?? 0;
            $sale = $r->find('customer_sales', (int)($last['saleId'] ?? 0));
            if (!is_int($amount) || $amount <= 0 || $amount > ($before['unallocatedMinor'] ?? 0) || $sale['CUSTOMER_ID'] !== $d['CUSTOMER_ID'] || $sale['STATUS'] !== 'invoiced' || $amount > NativeReturns::balance($r, $sale) || $last !== ['saleId' => $sale['_ID'],'amountMinor' => $amount,'receivableMinor' => 0,'depositMinor' => $amount]) {
                throw ValidationException::field('allocation', 'Invalid customer advance application');
            }
            $expected['allocations'][] = $last;
            $expected['unallocatedMinor'] -= $amount;
            if ($after !== $expected) {
                throw ValidationException::field('allocation', 'Receipt financial snapshot is immutable');
            }return [];
        }
        if ($operation === 'delete') {
            return [];
        }if (!in_array($state, ['draft','completed'], true)) {
            throw ValidationException::field($f['STATUS'], 'Invalid payment state');
        }$amount = NativeLedger::minor($d['AMOUNT']);
        if ($amount <= 0) {
            throw ValidationException::field($f['AMOUNT'], 'Receive a positive amount');
        }$customer = $r->find('customer', (int)$d['CUSTOMER_ID']);
        if (isset($d['POSTED_JOURNAL_ID'])) {
            throw ValidationException::field($f['POSTED_JOURNAL_ID'], 'Journal linkage is derived from its authoritative source');
        }$candidates = [];
        if (!empty($d['SALE_ID'])) {
            $sale = $r->find('customer_sales', $d['SALE_ID']);
            if ($sale['CUSTOMER_ID'] !== $d['CUSTOMER_ID']) {
                throw ValidationException::field($f['SALE_ID'], 'Payment customer must match its sale');
            }if ($amount > NativeReturns::balance($r, $sale)) {
                throw ValidationException::field($f['AMOUNT'], 'Payment exceeds remaining sale amount');
            }$candidates = [$sale];
        } else {
            $candidates = array_values(array_filter($r->rows('customer_sales'), fn ($s) => $s['CUSTOMER_ID'] === $d['CUSTOMER_ID'] && $s['STATUS'] === 'invoiced'));
            usort($candidates, fn ($a, $b) => [$a['SALE_DATE'],$a['_ID']] <=> [$b['SALE_DATE'],$b['_ID']]);
        }
        if ($state === 'draft') {
            return [];
        }(new NativeLedger())->authorizePosting($ctx);
        if ($d['PAYMENT_DATE'] > gmdate('Y-m-d H:i:s')) {
            throw ValidationException::field($f['PAYMENT_DATE'], 'Future receipts must remain draft');
        }$remaining = $amount;
        $receivable = 0;
        $allocations = [];
        foreach ($candidates as $sale) {
            $used = min($remaining, NativeReturns::balance($r, $sale));
            if (!$used) {
                continue;
            }$ar = min($used, NativeReturns::balance($r, $sale, (int)($sale['_PRIVATE']['recognizedMinor'] ?? 0)));
            $allocations[] = ['saleId' => $sale['_ID'],'amountMinor' => $used,'receivableMinor' => $ar,'depositMinor' => $used - $ar];
            $receivable += $ar;
            $remaining -= $used;
            if (!$remaining) {
                break;
            }
        }
        return ['proposedData' => ['settings' => ['cement' => ['allocations' => $allocations,'unallocatedMinor' => $remaining,'depositMinor' => $amount - $receivable,'receivableMinor' => $receivable]]]];
    }
    public static function effects(array $record, int $id, string $operation, MutationContext $ctx): array
    {
        $r = new NativeRecords($ctx);
        $d = $r->decode('customer_payment', $record);
        if ($operation === 'delete' || $d['STATUS'] !== 'completed' || TrustedPlans::origin() === 'cement.advance') {
            return [];
        }$settings = $r->settings();
        $amount = NativeLedger::minor($d['AMOUNT']);
        $private = $record['settings']['cement'];
        $ar = $private['receivableMinor'];
        $deposit = $private['depositMinor'];
        $bank = (int)($settings['cementReceiptBankAccountId'] ?? 0);
        if (!empty($d['BANK_ACCOUNT_ID'])) {
            $account = $r->raw('bank_account', $d['BANK_ACCOUNT_ID']);
            if ($account['status'] !== 'active' || $account['currencyCode'] !== ($settings['defaultCurrency'] ?? 'NGN')) {
                throw ValidationException::field('bank', 'Bank account must be active in institution currency');
            }$bank = (int)$account['glAccountId'];
        }
        $receivable = (int)($r->find('customer', $d['CUSTOMER_ID'])['ACCOUNT_ID'] ?? $settings['cementReceivablesAccountId'] ?? 0);
        $entries = [[$bank,$amount,0]];
        if ($ar) {
            $entries[] = [$receivable,0,$ar];
        }if ($deposit) {
            $entries[] = [(int)($settings['cementCustomerDepositsAccountId'] ?? 0),0,$deposit];
        }$plans = (new NativeLedger())->postingPlans($ctx, 'customer_payment', $id, 'CEMENT-RECEIPT-'.$id, 'Customer receipt '.$d['CODE'], $entries);
        foreach ($private['allocations'] as $a) {
            $sale = $r->find('customer_sales', $a['saleId']);
            $p = $r->plan('customer_sales', ['CODE' => $sale['CODE']], $sale['_ID']);
            $p['_cementGeneratedBy'] = 'cement.payment';
            $plans[] = $p;
        }return ['mutationPlans' => $plans];
    }
}
