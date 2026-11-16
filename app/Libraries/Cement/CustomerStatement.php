<?php

namespace App\Libraries\Cement;

final class CustomerStatement
{
    public function __construct(private Store $s)
    {
    }
    public function generate(array $actor, array $query): array
    {
        foreach (['customer','customer_sales','customer_payment','customer_sale_return'] as $type) {
            if (!$this->s->catalog->allowed($actor['role'], $type)) {
                throw new Problem('Customer statement is not authorized', 403);
            }
        }
        foreach (['from','to'] as $key) {
            if (!empty($query[$key]) && (!is_string($query[$key]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $query[$key]) || date('Y-m-d', strtotime($query[$key])) !== $query[$key])) {
                throw new Problem('Choose a valid statement date');
            }
        }if (!empty($query['from']) && !empty($query['to']) && $query['from'] > $query['to']) {
            throw new Problem('Statement start must precede its end');
        }
        $i = (int)$actor['institution_id'];
        $id = (int)($query['customerId'] ?? 0);
        $customer = $this->s->semantic($i, 'customer', $id);
        $events = [];
        $sales = [];
        foreach ($this->s->related($i, 'customer_sales', 'CUSTOMER_ID', $id) as $r) {
            $d = $this->s->catalog->decode('customer_sales', $r['data']);
            $sales[$r['id']] = $d;
            foreach ($this->s->run('SELECT * FROM sale_recognitions WHERE institution_id=? AND sale_id=?', [$i,$r['id']])->fetchAll(\PDO::FETCH_ASSOC) as $v) {
                $events[] = ['sequence' => (int)$this->s->run('SELECT COALESCE(MIN(id),0) FROM journals WHERE institution_id=? AND source LIKE ?', [$i,str_starts_with($v['source'], 'invoice:') ? 'sale:'.$r['id'].':%' : $v['source'].':%'])->fetchColumn(),'at' => $v['created_at'],'reference' => $d['CODE'],'kind' => 'Invoice / accepted delivery','debit' => $v['amount_minor'] / 100,'credit' => 0,'deposit' => 0,'cash' => 0,'refund' => 0];
            }
            foreach ($this->s->run('SELECT * FROM journals WHERE institution_id=? AND (source=? OR source LIKE ?)', [$i,'sale:'.$r['id'].':deposit','delivery:%:deposit'])->fetchAll(\PDO::FETCH_ASSOC) as $j) {
                if (str_starts_with($j['source'], 'delivery:')) {
                    $proofId = (int)explode(':', $j['source'])[1];
                    $proof = $this->s->semantic($i, 'delivery_proof', $proofId);
                    if ($proof['SALE_ID'] !== $r['id']) {
                        continue;
                    }
                }
                $amount = $j['amount_minor'] / 100;
                $events[] = ['sequence' => (int)$j['id'],'at' => $j['created_at'],'reference' => $d['CODE'],'kind' => 'Customer deposit applied','debit' => 0,'credit' => $amount,'deposit' => -$amount,'cash' => 0,'refund' => 0];
            }
        }
        foreach ($this->s->related($i, 'customer_payment', 'CUSTOMER_ID', $id) as $r) {
            $d = $this->s->catalog->decode('customer_payment', $r['data']);
            if ($d['STATUS'] !== 'completed') {
                continue;
            }$credit = 0;
            $deposit = 0;
            $sequence = PHP_INT_MAX;
            $at = $r['updated_at'];
            foreach ($this->s->run('SELECT * FROM journals WHERE institution_id=? AND (source=? OR source LIKE ?)', [$i,'customer_payment:'.$r['id'],'customer_payment:'.$r['id'].':%'])->fetchAll(\PDO::FETCH_ASSOC) as $j) {
                $sequence = min($sequence, (int)$j['id']);
                $at = $j['created_at'];
                if ($j['credit_account'] === 'receivables') {
                    $credit += $j['amount_minor'] / 100;
                }if ($j['credit_account'] === 'customer_deposits') {
                    $deposit += $j['amount_minor'] / 100;
                }
            }
            $events[] = ['sequence' => $sequence,'at' => $at,'reference' => $d['CODE'],'kind' => empty($d['SALE_ID']) ? 'Advance receipt' : 'Receipt','debit' => 0,'credit' => $credit,'deposit' => $deposit,'cash' => $d['AMOUNT'],'refund' => 0];
        }
        foreach ($this->s->all($i, 'customer_sale_return') as $r) {
            $d = $this->s->catalog->decode('customer_sale_return', $r['data']);
            if ($d['STATUS'] !== 'confirmed' || !isset($sales[$d['SALE_ID']])) {
                continue;
            }$credit = 0;
            $refund = 0;
            $sequence = PHP_INT_MAX;
            $at = $r['updated_at'];
            foreach ($this->s->run('SELECT * FROM journals WHERE institution_id=? AND source LIKE ?', [$i,'return:'.$r['id'].':%'])->fetchAll(\PDO::FETCH_ASSOC) as $j) {
                $sequence = min($sequence, (int)$j['id']);
                $at = $j['created_at'];
                if ($j['credit_account'] === 'receivables') {
                    $credit += $j['amount_minor'] / 100;
                } elseif (str_ends_with($j['source'], ':refund')) {
                    $refund += $j['amount_minor'] / 100;
                }
            }$events[] = ['sequence' => $sequence,'at' => $at,'reference' => $d['CODE'],'kind' => 'Return / credit / refund','debit' => 0,'credit' => $credit,'deposit' => 0,'cash' => 0,'refund' => $refund];
        }
        usort($events, fn ($a, $b) => [$a['at'],$a['sequence']] <=> [$b['at'],$b['sequence']]);
        $balance = 0;
        $deposit = 0;
        $opening = 0;
        $openingDeposit = 0;
        $rows = [];
        foreach ($events as $e) {
            $date = substr($e['at'], 0, 10);
            if (!empty($query['to']) && $date > $query['to']) {
                continue;
            }$balance = round($balance + $e['debit'] - $e['credit'], 2);
            $deposit = round($deposit + $e['deposit'], 2);
            if (!empty($query['from']) && $date < $query['from']) {
                $opening = $balance;
                $openingDeposit = $deposit;
                continue;
            }$rows[] = ['Posting date' => $date,'Reference' => $e['reference'],'Description' => $e['kind'],'Debit' => $e['debit'],'Credit' => $e['credit'],'Balance' => $balance,'Deposit change' => $e['deposit'],'Deposit balance' => $deposit,'Cash received' => $e['cash'],'Cash refunded' => $e['refund']];
        }
        return ['title' => 'Customer statement · '.($customer['COMPANY_NAME'] ?? $customer['CODE']),'openingReceivable' => $opening,'closingReceivable' => $balance,'openingDeposit' => $openingDeposit,'closingDeposit' => $deposit,'rows' => $rows,'rowCount' => count($rows),'columns' => ['Posting date','Reference','Description','Debit','Credit','Balance','Deposit change','Deposit balance','Cash received','Cash refunded']];
    }
}
