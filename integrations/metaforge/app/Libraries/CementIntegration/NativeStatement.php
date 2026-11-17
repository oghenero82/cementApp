<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Database\RecordRepository;
use App\Libraries\MetaForge\Mutation\MutationContext;

/** Customer-specific projection of posted shared journals, without exposing general ledger rows. */
final class NativeStatement
{
    public static function generate(MutationContext $ctx, array $query): array
    {
        $r = new NativeRecords($ctx);
        foreach (['customer','customer_sales','customer_payment','delivery_proof'] as $type) {
            $r->meta($type);
        }
        foreach (['from','to'] as $key) {
            if (!empty($query[$key]) && (!is_string($query[$key]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $query[$key]) || gmdate('Y-m-d', strtotime($query[$key].' UTC')) !== $query[$key])) {
                throw new \InvalidArgumentException('Choose a valid statement date');
            }
        }
        if (!empty($query['from']) && !empty($query['to']) && $query['from'] > $query['to']) {
            throw new \InvalidArgumentException('Statement start must precede its end');
        }
        if (!isset($query['customerId']) || !ctype_digit((string)$query['customerId'])) {
            throw new \InvalidArgumentException('Choose a customer');
        }$customer = $r->find('customer', (int)$query['customerId']);
        $sales = [];
        $payments = [];
        foreach ($r->rows('customer_sales') as $sale) {
            if ($sale['CUSTOMER_ID'] === $customer['_ID']) {
                $sales[$sale['_ID']] = $sale;
            }
        }
        foreach ($r->rows('customer_payment') as $payment) {
            if ($payment['CUSTOMER_ID'] === $customer['_ID'] && $payment['STATUS'] === 'completed') {
                $payments[$payment['_ID']] = $payment;
            }
        }
        // This narrowly scoped read is authorized by the business records above. General ledger
        // grants are not added to sales roles, and no journal/account data leaves this projection.
        $repo = new RecordRepository(\Config\Database::connect());
        $jm = $ctx->catalog->metadata('journal');
        $lm = $ctx->catalog->metadata('journal_line');
        $saleType = M::id($ctx->catalog->metadata('customer_sales'));
        $paymentType = M::id($ctx->catalog->metadata('customer_payment'));
        $journals = [];
        foreach ($repo->query($repo->table($ctx, $jm), M::id($jm)) as $row) {
            $j = M::properties($row);
            $kind = null;
            $ref = '';
            $source = $j['sourceRecordId'] ?? 0;
            if ($j['status'] !== 'POSTED') {
                continue;
            }
            if (($j['sourceRecordTypeId'] ?? null) === $saleType && isset($sales[$source])) {
                if (str_starts_with($j['code'], 'CEMENT-SALE-'.$source.'-')) {
                    $kind = 'Invoice / accepted delivery';
                } elseif (str_starts_with($j['code'], 'CEMENT-DEPOSIT-'.$source.'-')) {
                    $kind = 'Customer deposit applied';
                }$ref = $sales[$source]['CODE'];
            }
            if (($j['sourceRecordTypeId'] ?? null) === $paymentType && isset($payments[$source]) && $j['code'] === 'CEMENT-RECEIPT-'.$source) {
                $kind = 'Receipt';
                $ref = $payments[$source]['CODE'];
            }
            if ($kind !== null) {
                $journals[M::id($row)] = ['date' => $j['transactionDate'],'reference' => $ref,'kind' => $kind,'payment' => $kind === 'Receipt' ? $payments[$source] : null,'debit' => 0,'credit' => 0];
            }
        }
        foreach ($repo->query($repo->table($ctx, $lm), M::id($lm)) as $row) {
            $line = M::properties($row);
            if (isset($journals[$line['journalId']])) {
                $journals[$line['journalId']]['debit'] += NativeLedger::minor($line['debit']);
                $journals[$line['journalId']]['credit'] += NativeLedger::minor($line['credit']);
            }
        }
        uksort($journals, fn ($a, $b) => [$journals[$a]['date'],$a] <=> [$journals[$b]['date'],$b]);
        $balance = 0;
        $deposit = 0;
        $opening = 0;
        $openingDeposit = 0;
        $rows = [];
        foreach ($journals as $j) {
            $date = $j['date'];
            if (!empty($query['to']) && $date > $query['to']) {
                continue;
            }if ($j['debit'] <= 0 || $j['debit'] !== $j['credit']) {
                throw new \RuntimeException('Unbalanced posted customer journal');
            }$amount = $j['debit'];
            $dr = 0;
            $cr = 0;
            $dp = 0;
            $cash = 0;
            if ($j['kind'] === 'Receipt') {
                $p = $j['payment'];
                if ($amount !== NativeLedger::minor($p['AMOUNT'])) {
                    throw new \RuntimeException('Receipt and journal disagree');
                }$dp = (int)($p['_PRIVATE']['depositMinor'] ?? 0);
                $cr = $amount - $dp;
                $cash = $amount;
            } elseif ($j['kind'] === 'Customer deposit applied') {
                $cr = $amount;
                $dp = -$amount;
            } else {
                $dr = $amount;
            }
            $balance += $dr - $cr;
            $deposit += $dp;
            if (!empty($query['from']) && $date < $query['from']) {
                $opening = $balance;
                $openingDeposit = $deposit;
                continue;
            }
            $rows[] = ['Posting date' => $date,'Reference' => $j['reference'],'Description' => $j['kind'],'Debit' => $dr / 100,'Credit' => $cr / 100,'Balance' => $balance / 100,'Deposit change' => $dp / 100,'Deposit balance' => $deposit / 100,'Cash received' => $cash / 100,'Cash refunded' => 0];
        }
        return ['title' => 'Customer statement · '.($customer['COMPANY_NAME'] ?? $customer['CODE']),'openingReceivable' => $opening / 100,'closingReceivable' => $balance / 100,'openingDeposit' => $openingDeposit / 100,'closingDeposit' => $deposit / 100,'rows' => $rows,'rowCount' => count($rows),'columns' => ['Posting date','Reference','Description','Debit','Credit','Balance','Deposit change','Deposit balance','Cash received','Cash refunded']];
    }
}
