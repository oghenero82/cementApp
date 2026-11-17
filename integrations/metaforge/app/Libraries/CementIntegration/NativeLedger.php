<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Database\RecordRepository;
use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Verified shared Accounting hooks. All writes are plans executed by the outer native transaction. */
final class NativeLedger
{
    public static function register($registry): void
    {
        $h = new self();
        $inputs = [['name' => 'recordTypeCode','type' => 'string'],['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object'],['name' => 'operation','type' => 'string']];
        $registry->register('cement.ledger.validate', $h, 'validate', $inputs);
        $registry->register('cement.ledger.balances', $h, 'balances', [['name' => 'record','type' => 'object'],['name' => 'recordId','type' => 'integer']]);
        $registry->register('cement.purchase.fund', $h, 'fund', [['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']]);
    }
    public static function minor(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException('Money must be an exact decimal');
        }
        if (!preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/D', (string)$value, $m)) {
            throw new \InvalidArgumentException('Money must have at most two decimal places');
        }
        $minor = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        if ($minor > 100000000000000) {
            throw new \InvalidArgumentException('Posting amount exceeds the supported range');
        }return $minor;
    }
    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
    }
    private function repo(): RecordRepository
    {
        return new RecordRepository(\Config\Database::connect());
    }
    private function type(MutationContext $c, string $type, string $action = 'read'): array
    {
        return $c->type(M::id($c->catalog->metadata($type)), $action);
    }
    private function rows(MutationContext $c, string $type): array
    {
        $m = $this->type($c, $type);
        $r = $this->repo();
        return $r->query($r->table($c, $m), M::id($m));
    }
    private function find(MutationContext $c, string $type, int $id): array
    {
        $m = $this->type($c, $type);
        $r = $this->repo();
        $row = $r->find($r->table($c, $m), M::id($m), $id, true);
        if (!$row || M::physical($row, 'isDeleted', 0)) {
            throw ValidationException::field('reference', 'Accounting reference is not available');
        }return M::properties($row);
    }
    private function approver(MutationContext $c): void
    {
        $role = (new \App\Libraries\MetaForge\Auth\AuthRepository(\Config\Database::connect()))->role($c->auth['roleId']);
        if (!in_array(M::code($role), ['developer_role','cement_system_admin','cement_admin','cement_accountant'], true)) {
            throw ValidationException::field('status', 'An administrator or accountant must authorize posting');
        }
    }
    private function account(MutationContext $c, int $id): array
    {
        $a = $this->find($c, 'account', $id);
        if (($a['allowPosting'] ?? false) !== true || strtoupper($a['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            throw ValidationException::field('accountId', 'Posting account is inactive or does not permit posting');
        }return $a;
    }
    private function year(MutationContext $c, int $id, string $date): array
    {
        $y = $this->find($c, 'fiscal_year', $id);
        if (strtoupper($y['status'] ?? '') !== 'OPEN' || $date < $y['startDate'] || $date > $y['endDate'] || $date > gmdate('Y-m-d')) {
            throw ValidationException::field('transactionDate', 'Posting requires an open fiscal year and a non-future date inside that year');
        }return $y;
    }
    public function validate(string $recordTypeCode, array $record, array $previous, string $operation, MutationContext $context): array
    {
        if ($recordTypeCode === 'account_balance') {
            if ($operation === 'delete') {
                throw ValidationException::field('accountId', 'Account balances are generated and cannot be deleted');
            }
            if ($previous) {
                foreach (['accountId','fiscalYearId','periodType','periodStart','periodEnd'] as $key) {
                    if (($previous[$key] ?? null) !== ($record[$key] ?? null)) {
                        throw ValidationException::field($key, 'Balance identity is immutable');
                    }
                }
            }
            return ['proposedData' => $this->balanceData($context, (int)($record['accountId'] ?? 0), (int)($record['fiscalYearId'] ?? 0))];
        }
        if ($recordTypeCode === 'journal_line') {
            foreach (array_filter([$previous['journalId'] ?? null,$record['journalId'] ?? null]) as $id) {
                if (strtoupper($this->find($context, 'journal', (int)$id)['status'] ?? '') !== 'DRAFT') {
                    throw ValidationException::field('journalId', 'Lines of a posted journal are immutable');
                }
            }
            if ($operation !== 'delete') {
                $dr = self::minor($record['debit'] ?? 0);
                $cr = self::minor($record['credit'] ?? 0);
                if (($dr > 0) == ($cr > 0)) {
                    throw ValidationException::field('debit', 'A journal line requires exactly one positive debit or credit');
                }$this->account($context, (int)$record['accountId']);
            }
            return [];
        }
        if ($operation === 'delete') {
            throw ValidationException::field('status', 'Journals retain their audit history; reverse a posting instead');
        }
        if (strtoupper($previous['status'] ?? '') === 'POSTED') {
            throw ValidationException::field('status', 'Posted journals are immutable');
        }
        $state = strtoupper($record['status'] ?? '');
        if ($state === 'DRAFT') {
            return [];
        }
        if (!in_array($state, ['WAIT_POSTING','POSTED'], true) || !$previous) {
            throw ValidationException::field('status', 'Post an existing draft journal');
        }
        $this->approver($context);
        $this->year($context, (int)$record['fiscalYearId'], $record['transactionDate']);
        $journalId = null;
        foreach ($this->rows($context, 'journal') as $row) {
            if (M::property($row, 'code') === ($previous['code'] ?? null)) {
                $journalId = M::id($row);
                break;
            }
        }
        $dr = 0;
        $cr = 0;
        $count = 0;
        $currency = null;
        foreach ($this->rows($context, 'journal_line') as $row) {
            $line = M::properties($row);
            if ($line['journalId'] !== $journalId) {
                continue;
            }$dr += self::minor($line['debit']);
            $cr += self::minor($line['credit']);
            $a = $this->account($context, $line['accountId']);
            $current = $a['currencyCode'] ?? null;
            if ($currency !== null && $currency !== $current) {
                throw ValidationException::field('accountId', 'A journal cannot mix account currencies');
            }$currency = $current;
            $count++;
        }
        if ($count < 2 || $dr <= 0 || $dr !== $cr) {
            throw ValidationException::field('status', 'Posting requires at least two balanced journal lines');
        }
        if ($state === 'WAIT_POSTING') {
            return [];
        }
        return ['proposedData' => ['status' => 'POSTED','postedAt' => gmdate('Y-m-d H:i:s'),'postedBy' => $context->auth['user']['recordId']]];
    }
    private function balanceData(MutationContext $c, int $account, int $year): array
    {
        $this->account($c, $account);
        $fy = $this->find($c, 'fiscal_year', $year);
        $journals = [];
        foreach ($this->rows($c, 'journal') as $row) {
            $d = M::properties($row);
            if (strtoupper($d['status'] ?? '') === 'POSTED') {
                $journals[M::id($row)] = $d;
            }
        }
        $opening = 0;
        $dr = 0;
        $cr = 0;
        foreach ($this->rows($c, 'journal_line') as $row) {
            $line = M::properties($row);
            if ($line['accountId'] !== $account || !isset($journals[$line['journalId']])) {
                continue;
            }$j = $journals[$line['journalId']];
            $d = self::minor($line['debit']);
            $k = self::minor($line['credit']);
            if ($j['transactionDate'] < $fy['startDate']) {
                $opening += $d - $k;
            } elseif ($j['fiscalYearId'] === $year && $j['transactionDate'] <= $fy['endDate']) {
                $dr += $d;
                $cr += $k;
            }
        }
        $closing = $opening + $dr - $cr;
        return ['accountId' => $account,'fiscalYearId' => $year,'periodType' => 'year','periodStart' => $fy['startDate'],'periodEnd' => $fy['endDate'],'openingDebit' => self::decimal(max(0, $opening)),'openingCredit' => self::decimal(max(0, -$opening)),'periodDebit' => self::decimal($dr),'periodCredit' => self::decimal($cr),'closingDebit' => self::decimal(max(0, $closing)),'closingCredit' => self::decimal(max(0, -$closing)),'status' => 'ACTIVE'];
    }
    public function balances(array $record, int $recordId, MutationContext $context): array
    {
        if (strtoupper($record['status'] ?? '') !== 'POSTED') {
            return [];
        }$accounts = [];
        foreach ($this->rows($context, 'journal_line') as $row) {
            if (M::property($row, 'journalId') === $recordId) {
                $accounts[M::property($row, 'accountId')] = true;
            }
        }
        $plans = [];
        $meta = $context->catalog->metadata('account_balance');
        foreach (array_keys($accounts) as $account) {
            $data = $this->balanceData($context, $account, $record['fiscalYearId']);
            $id = null;
            foreach ($this->rows($context, 'account_balance') as $row) {
                $old = M::properties($row);
                if ($old['accountId'] === $account && $old['fiscalYearId'] === $record['fiscalYearId'] && $old['periodType'] === 'year' && $old['periodStart'] === $data['periodStart'] && $old['periodEnd'] === $data['periodEnd']) {
                    $id = M::id($row);
                    break;
                }
            }
            $op = $id ? 'update' : 'insert';
            $this->type($context, 'account_balance', $op);
            $p = ['recordTypeId' => M::id($meta),'operation' => $op,'data' => $data];
            if ($id) {
                $p['recordId'] = $id;
            }$plans[] = $p;
        }return ['mutationPlans' => $plans];
    }
    public function authorizePosting(MutationContext $context): void
    {
        $this->approver($context);
    }
    public static function postingRef(string $code): string
    {
        return 'j'.substr(hash('sha256', $code), 0, 32);
    }
    public function postingPlans(MutationContext $context, string $sourceType, int $sourceId, string $code, string $description, array $entries): array
    {
        $this->approver($context);
        $date = gmdate('Y-m-d');
        $currency = (new NativeRecords($context))->settings()['defaultCurrency'] ?? 'NGN';
        $dr = 0;
        $cr = 0;
        foreach ($entries as $entry) {
            [$id,$debit,$credit] = $entry;
            if (!is_int($debit) || !is_int($credit) || $debit < 0 || $credit < 0 || ($debit > 0) == ($credit > 0)) {
                throw ValidationException::field('amount', 'Invalid balanced posting entry');
            }$account = $this->account($context, $id);
            if (($account['currencyCode'] ?? null) !== $currency) {
                throw ValidationException::field('currency', 'Posting accounts must match institution currency');
            }$dr += $debit;
            $cr += $credit;
        }
        if (count($entries) < 2 || $dr <= 0 || $dr !== $cr) {
            throw ValidationException::field('amount', 'Posting entries must balance');
        }
        $years = [];
        foreach ($this->rows($context, 'fiscal_year') as $row) {
            $y = M::properties($row);
            if (strtoupper($y['status'] ?? '') === 'OPEN' && $date >= $y['startDate'] && $date <= $y['endDate']) {
                $years[] = M::id($row);
            }
        }if (count($years) !== 1) {
            throw ValidationException::field('transactionDate', 'Configure exactly one open fiscal year for posting');
        }$year = $years[0];
        $this->year($context, $year, $date);
        foreach ($this->rows($context, 'journal') as $row) {
            if (M::property($row, 'code') === $code) {
                throw ValidationException::field('reference', 'This business effect already has a journal');
            }
        }
        $journal = $this->type($context, 'journal', 'insert');
        $this->type($context, 'journal', 'update');
        $line = $this->type($context, 'journal_line', 'insert');
        $ref = self::postingRef($code);
        $plans = [['recordTypeId' => M::id($journal),'operation' => 'insert','data' => ['code' => $code,'transactionDate' => $date,'fiscalYearId' => $year,'description' => $description,'sourceRecordTypeId' => M::id($context->catalog->metadata($sourceType)),'sourceRecordId' => $sourceId,'status' => 'DRAFT'],'_cementClientRef' => $ref]];
        foreach ($entries as $n => [$account,$debit,$credit]) {
            $plans[] = ['recordTypeId' => M::id($line),'operation' => 'insert','data' => ['lineNumber' => $n + 1,'accountId' => $account,'debit' => self::decimal($debit),'credit' => self::decimal($credit)],'_cementReferences' => ['journalId' => $ref]];
        }
        foreach (['WAIT_POSTING','POSTED'] as $state) {
            $plans[] = ['recordTypeId' => M::id($journal),'operation' => 'update','data' => ['status' => $state],'_cementRecordRef' => $ref];
        }
        return $plans;
    }
    public function fund(array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $aliases = M::properties($context->catalog->metadata('purchase'))['settings']['cementFieldAliases'];
        $d = [];
        $old = [];
        foreach ($aliases as $a => $key) {
            if (isset($record[$key])) {
                $d[$a] = $record[$key];
            }if (isset($previous[$key])) {
                $old[$a] = $previous[$key];
            }
        }
        if (($d['STATUS'] ?? '') !== 'paid') {
            return [];
        }
        if ($operation !== 'update' || $recordId <= 0 || ($old['STATUS'] ?? '') !== 'draft') {
            throw ValidationException::field($aliases['STATUS'], 'Only an existing draft purchase can be funded');
        }
        $this->approver($context);
        $gross = self::minor($d['NET_AMOUNT'] ?? 0);
        $partialEnabled = NativeSupplierPayments::enabled(new NativeRecords($context));
        $priorPaid = $partialEnabled ? self::minor($d['PAID_AMOUNT'] ?? 0) : 0;
        $amount = $gross - $priorPaid;
        if ($gross <= 0 || $amount < 0) {
            throw ValidationException::field($aliases['STATUS'], 'Purchase requires positive unfunded item amounts');
        }
        if ($amount === 0) {
            if (!$partialEnabled || TrustedPlans::origin() !== 'cement.supplier_payment') {
                throw ValidationException::field($aliases['STATUS'], 'Purchase funding already settled');
            }return ['proposedData' => [$aliases['PAID_AMOUNT'] => self::decimal($gross)]];
        }
        $payment = (int)($d['PAYMENT_ACCOUNT_ID'] ?? 0);
        $prepaid = (int)($d['PREPAID_ACCOUNT_ID'] ?? 0);
        if ($payment === $prepaid || !$payment || !$prepaid) {
            throw ValidationException::field($aliases['PAYMENT_ACCOUNT_ID'], 'Choose different payment and prepaid GL accounts');
        }
        foreach ([$payment,$prepaid] as $account) {
            $a = $this->account($context, $account);
            if (($a['currencyCode'] ?? null) !== $d['CURRENCY']) {
                throw ValidationException::field($aliases['CURRENCY'], 'Posting accounts must match the purchase currency');
            }
        }
        $date = gmdate('Y-m-d');
        $years = [];
        foreach ($this->rows($context, 'fiscal_year') as $row) {
            $y = M::properties($row);
            if (strtoupper($y['status'] ?? '') === 'OPEN' && $date >= $y['startDate'] && $date <= $y['endDate']) {
                $years[] = M::id($row);
            }
        }if (count($years) !== 1) {
            throw ValidationException::field($aliases['STATUS'], 'Configure one open fiscal year for the posting date');
        }$year = $years[0];
        $this->year($context, $year, $date);
        $journal = $this->type($context, 'journal', 'insert');
        $this->type($context, 'journal', 'update');
        $line = $this->type($context, 'journal_line', 'insert');
        $code = 'CEMENT-FUND-'.$recordId;
        foreach ($this->rows($context, 'journal') as $row) {
            if (M::property($row, 'code') === $code) {
                throw ValidationException::field($aliases['STATUS'], 'Purchase funding already has a journal');
            }
        }
        $plans = [['recordTypeId' => M::id($journal),'operation' => 'insert','data' => ['code' => $code,'transactionDate' => $date,'fiscalYearId' => $year,'description' => 'Cement purchase funding '.$d['CODE'],'reference' => $d['CODE'],'sourceRecordTypeId' => M::id($context->catalog->metadata('purchase')),'sourceRecordId' => $recordId,'status' => 'DRAFT'],'_cementClientRef' => 'fundingJournal']];
        foreach ([[$prepaid,self::decimal($amount),'0.00'],[$payment,'0.00',self::decimal($amount)]] as $n => [$account,$dr,$cr]) {
            $plans[] = ['recordTypeId' => M::id($line),'operation' => 'insert','data' => ['lineNumber' => $n + 1,'accountId' => $account,'debit' => $dr,'credit' => $cr],'_cementReferences' => ['journalId' => 'fundingJournal']];
        }
        $plans[] = ['recordTypeId' => M::id($journal),'operation' => 'update','data' => ['status' => 'WAIT_POSTING'],'_cementRecordRef' => 'fundingJournal'];
        $plans[] = ['recordTypeId' => M::id($journal),'operation' => 'update','data' => ['status' => 'POSTED'],'_cementRecordRef' => 'fundingJournal'];
        $proposed = [$aliases['PAID_AMOUNT'] => self::decimal($gross)];
        if ($partialEnabled) {
            $proposed['settings'] = ['cement' => array_replace($record['settings']['cement'] ?? [], ['fundingMinor' => (int)($record['settings']['cement']['fundingMinor'] ?? 0) + $amount])];
        }return ['proposedData' => $proposed,'mutationPlans' => $plans];
    }
}
