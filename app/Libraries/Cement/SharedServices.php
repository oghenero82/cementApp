<?php

namespace App\Libraries\Cement;

/** Local adapters for shared Accounting and Cooperative RecordTypes, never a second authority. */
final class SharedServices
{
    public function __construct(private Store $s)
    {
    }
    public function owned(): array
    {
        return ['account_balance' => ['BALANCE'],'member_account' => ['BALANCE'],'bank_reconciliation' => ['RECONCILED_AT','STATEMENT_BALANCE','LEDGER_BALANCE','DIFFERENCE']];
    }
    private function decode(array $r): array
    {
        return $this->s->catalog->decode($r['type'], $r['data']);
    }
    private function find(int $i, string $type, string $alias, mixed $value): ?array
    {
        foreach ($this->s->all($i, $type) as $r) {
            if (($this->decode($r)[$alias] ?? null) === $value) {
                return $this->decode($r) + ['_ID' => $r['id']];
            }
        }return null;
    }
    private function account(int $i, string $code): int
    {
        $a = $this->find($i, 'account', 'CODE', $code);
        if ($a) {
            if ($a['STATUS'] !== 'active') {
                throw new Problem('Posting account is inactive');
            }return $a['_ID'];
        }
        $category = match(true) {
            $code === 'retained_earnings' => 'equity',str_contains($code, 'income') => 'income',str_contains($code, 'expense') || in_array($code, ['cost_of_sales','sales_returns','stock_variance']) => 'expense',str_contains($code, 'payable') || $code === 'customer_deposits' => 'liability',default => 'asset'
        };
        $type = $this->find($i, 'account_type', 'CODE', $category);
        $typeId = $type['_ID'] ?? $this->s->save($i, 'account_type', ['CODE' => $category,'NAME' => ucwords($category),'CATEGORY' => $category,'STATUS' => 'active'])['id'];
        return $this->s->save($i, 'account', ['CODE' => $code,'NAME' => ucwords(str_replace('_', ' ', $code)),'ACCOUNT_TYPE_ID' => $typeId,'CURRENCY' => $this->s->settings($i)['defaultCurrency'],'STATUS' => 'active'])['id'];
    }
    public function recordPosting(int $i, int $sqlId, string $source, string $debit, string $credit, int $minor): void
    {
        $debitId = $this->account($i, $debit);
        $creditId = $this->account($i, $credit);
        $canonical = preg_match('/^([a-zA-Z_]+:\d+)/', $source, $match) ? $match[1] : $source;
        $existing = $this->find($i, 'journal', 'SOURCE', $canonical);
        $journal = $this->s->manualJournalId ?? $existing['_ID'] ?? $this->s->save($i, 'journal', ['CODE' => 'JRN-'.substr(hash('sha256', $canonical), 0, 24),'POSTED_AT' => $this->s->run('SELECT created_at FROM journals WHERE id=?', [$sqlId])->fetchColumn(),'SOURCE' => $canonical,'STATUS' => 'posted'])['id'];
        if (preg_match('/^([a-zA-Z_]+):(\d+)/', $canonical, $origin) && isset($this->s->catalog->types[$origin[1]])) {
            $field = isset($this->s->catalog->fields[$origin[1]]['JOURNAL_ID']) ? 'JOURNAL_ID' : (isset($this->s->catalog->fields[$origin[1]]['POSTED_JOURNAL_ID']) ? 'POSTED_JOURNAL_ID' : null);
            if ($field) {
                $this->s->patch($i, $origin[1], (int)$origin[2], [$field => $journal]);
            }
        }
        $this->s->run('UPDATE journals SET record_id=? WHERE id=?', [$journal,$sqlId]);
        foreach ([[$debitId,$minor / 100,0],[$creditId,0,$minor / 100]] as [$account,$dr,$cr]) {
            if (!$this->s->manualJournalId) {
                $this->s->save($i, 'journal_line', ['JOURNAL_ID' => $journal,'ACCOUNT_ID' => $account,'DEBIT' => $dr,'CREDIT' => $cr,'STATUS' => 'posted']);
            }
            $balance = $this->find($i, 'account_balance', 'ACCOUNT_ID', $account);
            $this->s->save($i, 'account_balance', ['CODE' => 'BAL-'.$account,'ACCOUNT_ID' => $account,'BALANCE' => round(($balance['BALANCE'] ?? 0) + $dr - $cr, 2),'STATUS' => 'active'], $balance['_ID'] ?? null);
        }
    }
    public function prepare(array $actor, string $type, array $d, array $old): array
    {
        $i = (int)$actor['institution_id'];
        if ($type === 'member_account') {
            if (!in_array($d['ACCOUNT_TYPE'] ?? '', ['savings','loan'])) {
                throw new Problem('Member account type must be savings or loan');
            }$d['BALANCE'] = $old['BALANCE'] ?? 0;
            if ($old) {
                foreach (['MEMBER_ID','ACCOUNT_TYPE'] as $key) {
                    if (($d[$key] ?? null) !== ($old[$key] ?? null)) {
                        throw new Problem('Member account identity cannot be changed');
                    }
                }
            }
            if (($d['BALANCE_LIMIT'] ?? 0) < 0) {
                throw new Problem('Loan limit cannot be negative');
            }
        }
        if ($type === 'account_type' && $old && $old['CATEGORY'] !== $d['CATEGORY']) {
            foreach ($this->s->all($i, 'account') as $r) {
                if ($this->decode($r)['ACCOUNT_TYPE_ID'] === ($this->find($i, 'account_type', 'CODE', $old['CODE'])['_ID'] ?? null)) {
                    throw new Problem('A used account classification cannot be changed');
                }
            }
        }
        if ($type === 'account' && $old) {
            foreach (['CODE','ACCOUNT_TYPE_ID','CURRENCY'] as $key) {
                if (($d[$key] ?? null) !== ($old[$key] ?? null)) {
                    throw new Problem('Account identity is immutable; create a new account');
                }
            }
        }
        if ($type === 'fiscal_year') {
            if (($d['START_DATE'] ?? '') > ($d['END_DATE'] ?? '')) {
                throw new Problem('Fiscal year dates are reversed');
            }
            foreach ($this->s->all($i, 'fiscal_year') as $r) {
                $other = $this->decode($r);
                if (($other['CODE'] ?? null) !== ($d['CODE'] ?? null) && $d['START_DATE'] <= $other['END_DATE'] && $d['END_DATE'] >= $other['START_DATE']) {
                    throw new Problem('Fiscal years cannot overlap');
                }
            }
            if (($d['STATUS'] ?? '') === 'closed' && $d['END_DATE'] >= gmdate('Y-m-d')) {
                throw new Problem('A fiscal period can close only after its end date');
            }
        }
        if ($type === 'journal' && str_contains($d['SOURCE'] ?? '', ':')) {
            throw new Problem('Colons are reserved for generated journal source references');
        }
        if ($type === 'journal_line') {
            $journal = $this->s->semantic($i, 'journal', (int)($d['JOURNAL_ID'] ?? 0));
            if ($journal['STATUS'] === 'posted') {
                throw new Problem('Posted journal lines are immutable');
            }
            if (($d['DEBIT'] ?? 0) < 0 || ($d['CREDIT'] ?? 0) < 0 || (($d['DEBIT'] ?? 0) > 0) === (($d['CREDIT'] ?? 0) > 0)) {
                throw new Problem('A journal line needs exactly one positive debit or credit');
            }
        }
        if ($type === 'bank_statement_line') {
            $statement = $this->s->semantic($i, 'bank_statement', (int)($d['STATEMENT_ID'] ?? 0));
            if ($statement['STATUS'] === 'reconciled') {
                throw new Problem('Reconciled statements cannot be changed');
            }
            if ($d['TRANSACTION_DATE'] < $statement['PERIOD_START'] || $d['TRANSACTION_DATE'] > $statement['PERIOD_END']) {
                throw new Problem('Statement line is outside the statement period');
            }
        }
        return $d;
    }
    public function after(array $actor, string $type, int $id, array $d): void
    {
        $i = (int)$actor['institution_id'];
        if (!in_array($d['STATUS'] ?? '', ['posted','confirmed','closed'])) {
            return;
        }
        if (!in_array($actor['role'], ['cement_admin','cement_system_admin','cement_accountant'])) {
            throw new Problem('Accounting approval required', 403);
        }
        if ($type === 'journal') {
            $lines = $this->s->related($i, 'journal_line', 'JOURNAL_ID', $id);
            $debits = [];
            $credits = [];
            $dr = 0;
            $cr = 0;
            foreach ($lines as $r) {
                $line = $this->decode($r);
                $account = $this->s->semantic($i, 'account', $line['ACCOUNT_ID'])['CODE'];
                $a = (int)round($line['DEBIT'] * 100);
                $b = (int)round($line['CREDIT'] * 100);
                $dr += $a;
                $cr += $b;
                if ($a) {
                    $debits[] = [$account,$a];
                }if ($b) {
                    $credits[] = [$account,$b];
                }
            }
            if (!$dr || $dr !== $cr) {
                throw new Problem('Journal must have equal nonzero debits and credits');
            }
            $n = 0;
            $this->s->manualJournalId = $id;
            try {
                while ($debits && $credits) {
                    $amount = min($debits[0][1], $credits[0][1]);
                    $this->s->journal($i, 'manual:'.$id.':'.$n++, $debits[0][0], $credits[0][0], $amount / 100);
                    $debits[0][1] -= $amount;
                    $credits[0][1] -= $amount;
                    if (!$debits[0][1]) {
                        array_shift($debits);
                    }if (!$credits[0][1]) {
                        array_shift($credits);
                    }
                }
            } finally {
                $this->s->manualJournalId = null;
            }
            foreach ($lines as $r) {
                $this->s->patch($i, 'journal_line', $r['id'], ['STATUS' => 'posted']);
            }
        }
        if ($type === 'fiscal_year') {
            $balances = [];
            foreach ($this->s->run('SELECT * FROM journals WHERE institution_id=? AND substr(created_at,1,10)<=?', [$i,$d['END_DATE']])->fetchAll(\PDO::FETCH_ASSOC) as $j) {
                $balances[$j['debit_account']] = ($balances[$j['debit_account']] ?? 0) + $j['amount_minor'];
                $balances[$j['credit_account']] = ($balances[$j['credit_account']] ?? 0) - $j['amount_minor'];
            }
            foreach ($this->s->all($i, 'account') as $r) {
                $account = $this->decode($r);
                $category = $this->s->semantic($i, 'account_type', $account['ACCOUNT_TYPE_ID'])['CATEGORY'];
                $balance = $balances[$account['CODE']] ?? 0;
                if (!$balance || !in_array($category, ['income','expense'])) {
                    continue;
                }$this->s->journal($i, 'year_close:'.$id.':'.$account['CODE'], $balance > 0 ? 'retained_earnings' : $account['CODE'], $balance > 0 ? $account['CODE'] : 'retained_earnings', abs($balance) / 100, $d['END_DATE'].'T23:59:59+00:00');
            }
        }
        if ($type === 'bank_reconciliation') {
            $this->reconcile($i, $id, $d);
        }
        if ($type === 'cooperative_transaction') {
            $this->cooperative($i, $id, $d);
        }
    }
    private function reconcile(int $i, int $id, array $d): void
    {
        $statement = $this->s->semantic($i, 'bank_statement', $d['STATEMENT_ID']);
        if ($statement['STATUS'] === 'reconciled') {
            throw new Problem('Statement already reconciled');
        }
        $bank = $this->s->semantic($i, 'bank_account', $statement['BANK_ACCOUNT_ID']);
        $account = $this->s->semantic($i, 'account', $bank['ACCOUNT_ID'])['CODE'];
        $lines = $this->s->related($i, 'bank_statement_line', 'STATEMENT_ID', $d['STATEMENT_ID']);
        $total = $statement['OPENING_BALANCE'];
        $used = [];
        foreach ($lines as $r) {
            $line = $this->decode($r);
            $total += $line['AMOUNT'];
            if (empty($line['JOURNAL_ID'])) {
                throw new Problem('Match every bank line to a journal before reconciliation');
            }
            if (isset($used[$line['JOURNAL_ID']])) {
                throw new Problem('A journal cannot match multiple statement lines');
            }$used[$line['JOURNAL_ID']] = true;
            $match = $this->s->run('SELECT COALESCE(SUM(CASE WHEN debit_account=? THEN amount_minor ELSE 0 END - CASE WHEN credit_account=? THEN amount_minor ELSE 0 END),0) FROM journals WHERE institution_id=? AND record_id=?', [$account,$account,$i,$line['JOURNAL_ID']])->fetchColumn();
            if ((int)round($line['AMOUNT'] * 100) !== (int)$match) {
                throw new Problem('Bank line amount differs from its matched journal');
            }
            foreach ($this->s->all($i, 'bank_statement_line') as $other) {
                if ($other['id'] === $r['id']) {
                    continue;
                }$o = $this->decode($other);
                if (($o['JOURNAL_ID'] ?? null) === $line['JOURNAL_ID'] && $o['STATEMENT_ID'] !== $d['STATEMENT_ID']) {
                    throw new Problem('Journal was already matched on another statement');
                }
            }
        }
        if (abs($total - $statement['CLOSING_BALANCE']) > 0.005) {
            throw new Problem('Opening balance plus statement lines differs from closing balance');
        }
        $ledger = $this->s->run('SELECT COALESCE(SUM(CASE WHEN debit_account=? THEN amount_minor ELSE 0 END - CASE WHEN credit_account=? THEN amount_minor ELSE 0 END),0) FROM journals WHERE institution_id=? AND substr(created_at,1,10)<=?', [$account,$account,$i,$statement['PERIOD_END']])->fetchColumn() / 100;
        $difference = round($statement['CLOSING_BALANCE'] - $ledger, 2);
        if (abs($difference) > 0.005) {
            throw new Problem('Bank statement and ledger do not reconcile');
        }
        $this->s->patch($i, 'bank_reconciliation', $id, ['RECONCILED_AT' => gmdate('c'),'STATEMENT_BALANCE' => $statement['CLOSING_BALANCE'],'LEDGER_BALANCE' => $ledger,'DIFFERENCE' => $difference]);
        $this->s->patch($i, 'bank_statement', $d['STATEMENT_ID'], ['STATUS' => 'reconciled']);
    }
    public function changeMemberBalance(int $i, int $accountId, string $kind, float $amount): void
    {
        if ($amount <= 0 || !is_finite($amount)) {
            throw new Problem('Cooperative amount must be positive');
        }$account = $this->s->semantic($i, 'member_account', $accountId);
        $balance = $account['BALANCE'] ?? 0;
        $savings = in_array($kind, ['savings_contribution','savings_withdrawal']);
        if (($savings ? 'savings' : 'loan') !== $account['ACCOUNT_TYPE']) {
            throw new Problem('Cooperative transaction uses the wrong account type');
        }
        $delta = in_array($kind, ['savings_contribution','loan_disbursement']) ? $amount : -$amount;
        $next = round($balance + $delta, 2);
        if ($next < 0) {
            throw new Problem('Withdrawal or repayment exceeds the cooperative balance');
        }
        if ($kind === 'loan_disbursement' && $next > ($account['BALANCE_LIMIT'] ?? 0)) {
            throw new Problem('Loan exceeds approved member credit limit');
        }
        $this->s->patch($i, 'member_account', $accountId, ['BALANCE' => $next]);
    }
    private function cooperative(int $i, int $id, array $d): void
    {
        $kind = $d['TRANSACTION_TYPE'];
        if (!in_array($kind, ['savings_contribution','savings_withdrawal','loan_disbursement','loan_repayment'])) {
            throw new Problem('Unknown Cooperative transaction type');
        }
        $this->changeMemberBalance($i, $d['MEMBER_ACCOUNT_ID'], $kind, $d['AMOUNT']);
        [$dr,$cr] = match($kind) {
            'savings_contribution' => ['bank','savings_payable'],'savings_withdrawal' => ['savings_payable','bank'],'loan_disbursement' => ['staff_loans_receivable','bank'],'loan_repayment' => ['bank','staff_loans_receivable']
        };
        $this->s->journal($i, 'cooperative:'.$id, $dr, $cr, $d['AMOUNT']);
    }
}
