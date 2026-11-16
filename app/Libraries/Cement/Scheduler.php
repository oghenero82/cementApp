<?php

namespace App\Libraries\Cement;

/** Durable, idempotent report generation; invoke cement:reports from cron once per minute. */
final class Scheduler
{
    public function __construct(private Store $s)
    {
    }
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $outcomes = [];
        foreach ($this->s->run('SELECT id FROM institutions')->fetchAll(\PDO::FETCH_COLUMN) as $institution) {
            $i = (int)$institution;
            $actor = ['id' => 0,'institution_id' => $i,'role' => 'cement_system_admin'];
            foreach ($this->s->all($i, 'report_schedule') as $record) {
                $schedule = $this->s->catalog->decode('report_schedule', $record['data']);
                if (!($schedule['ENABLED'] ?? false) || $schedule['STATUS'] !== 'active') {
                    continue;
                }
                $local = $now->setTimezone(new \DateTimeZone($schedule['TIMEZONE']));
                $bucket = match($schedule['CADENCE']) {
                    'daily' => $local->format('Y-m-d'),'weekly' => $local->format('o-W'),'monthly' => $local->format('Y-m'),default => throw new Problem('Unsupported schedule cadence')
                };
                $key = 'schedule:'.$record['id'].':'.$bucket;
                $this->s->begin();
                try {
                    if ($this->s->run('SELECT id FROM mutations WHERE institution_id=? AND request_id=?', [$i,$key])->fetchColumn()) {
                        $this->s->commit();
                        continue;
                    }
                    $this->s->run('INSERT INTO mutations(institution_id,request_id,digest,created_at,response) VALUES (?,?,?,?,?)', [$i,$key,hash('sha256', $key),$now->format('c'),'{}']);
                    $this->s->mutationId = (int)$this->s->db->lastInsertId();
                    $definition = $this->s->semantic($i, 'report', $schedule['REPORT_ID']);
                    $result = (new Reports($this->s))->generate($actor, $definition['CODE']);
                    $saved = $this->s->save($i, 'report_result', ['CODE' => $key,'REPORT_ID' => $schedule['REPORT_ID'],'GENERATED_AT' => $now->format('c'),'PARAMETERS' => ['scheduleId' => $record['id'],'period' => $bucket,'authorizationSources' => $this->sources($definition['CODE']),'authorizationRoles' => $definition['ACCESS_ROLES'] ?? []],'TOTALS' => ['rowCount' => $result['rowCount']],'ROWS' => $result['rows'],'FILE_TYPE' => $schedule['OUTPUT_FORMAT'] ?? 'csv','STATUS' => 'completed']);
                    foreach ($schedule['RECIPIENTS'] ?? [] as $recipient) {
                        $this->s->save($i, 'report_delivery', ['REPORT_RESULT_ID' => $saved['id'],'DELIVERY_METHOD' => 'email','RECIPIENT' => $recipient,'DELIVERY_STATUS' => 'pending','RETRY_COUNT' => 0,'STATUS' => 'pending']);
                    }
                    $next = $local->modify(match($schedule['CADENCE']) {
                        'daily' => '+1 day','weekly' => '+1 week','monthly' => 'first day of next month'
                    })->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
                    $this->s->patch($i, 'report_schedule', $record['id'], ['LAST_RUN_AT' => $now->format('c'),'NEXT_RUN_AT' => $next->format('c')]);
                    $this->s->commit();
                    $outcomes[] = ['schedule' => $record['id'],'result' => $saved['id'],'state' => 'generated'];
                } catch (\Throwable $e) {
                    $this->s->rollback();
                    $outcomes[] = ['schedule' => $record['id'],'state' => 'failed','message' => $e->getMessage()];
                }
            }
            foreach ($this->s->all($i, 'report_delivery') as $record) {
                $delivery = $this->s->catalog->decode('report_delivery', $record['data']);
                if (!in_array($delivery['DELIVERY_STATUS'], ['pending','failed']) || ($delivery['RETRY_COUNT'] ?? 0) >= 3) {
                    continue;
                }
                if (!(getenv('CEMENT_REPORT_EMAIL_ENABLED') === '1')) {
                    $outcomes[] = ['delivery' => $record['id'],'state' => 'pending_configuration'];
                    continue;
                }
                try {
                    $this->s->begin();
                    $claim = $this->s->semantic($i, 'report_delivery', $record['id']);
                    if (!in_array($claim['DELIVERY_STATUS'], ['pending','failed'])) {
                        $this->s->commit();
                        continue;
                    }
                    $attempt = ($claim['RETRY_COUNT'] ?? 0) + 1;
                    $this->systemMutation($i, 'delivery:'.$record['id'].':attempt:'.$attempt);
                    $this->s->patch($i, 'report_delivery', $record['id'], ['DELIVERY_STATUS' => 'sending','RETRY_COUNT' => $attempt]);
                    $this->s->commit();
                    $report = $this->s->semantic($i, 'report_result', $delivery['REPORT_RESULT_ID']);
                    $definition = $this->s->semantic($i, 'report', $report['REPORT_ID']);
                    $rows = $report['ROWS'];
                    $export = ['columns' => array_keys($rows[0] ?? []),'rows' => $rows];
                    $xlsx = ($report['FILE_TYPE'] ?? 'csv') === 'xlsx';
                    $content = $xlsx ? (new Reports($this->s))->xlsx($export) : (new Reports($this->s))->csv($export);
                    $email = service('email');
                    $email->clear(true);
                    $email->setTo($delivery['RECIPIENT']);
                    $email->setSubject($definition['NAME']);
                    $email->setMessage('Your Cement App report is attached.');
                    $email->attach($content, 'attachment', 'cement-report.'.($xlsx ? 'xlsx' : 'csv'), $xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv');
                    if (!$email->send()) {
                        throw new Problem('Configured email transport did not accept the message');
                    }
                    $this->s->begin();
                    $this->systemMutation($i, 'delivery:'.$record['id'].':success:'.$attempt);
                    $this->s->patch($i, 'report_delivery', $record['id'], ['DELIVERY_STATUS' => 'delivered','DELIVERED_AT' => gmdate('c'),'STATUS' => 'completed']);
                    $this->s->commit();
                    $outcomes[] = ['delivery' => $record['id'],'state' => 'delivered'];
                } catch (\Throwable $e) {
                    if ($this->s->db->inTransaction()) {
                        $this->s->rollback();
                    }$this->s->begin();
                    $this->systemMutation($i, 'delivery:'.$record['id'].':failure:'.bin2hex(random_bytes(5)));
                    $this->s->patch($i, 'report_delivery', $record['id'], ['DELIVERY_STATUS' => 'failed','FAILURE_REASON' => $e->getMessage(),'RETRY_COUNT' => ($delivery['RETRY_COUNT'] ?? 0) + 1]);
                    $this->s->commit();
                    $outcomes[] = ['delivery' => $record['id'],'state' => 'failed'];
                }
            }
        }return $outcomes;
    }
    private function sources(string $code): array
    {
        foreach ($this->s->catalog->json('report_templates') as $template) {
            if ($template['code'] === $code) {
                return $template['sourceRecordTypes'];
            }
        }throw new Problem('Unknown scheduled report template');
    }
    private function systemMutation(int $i, string $request): void
    {
        $this->s->run('INSERT INTO mutations(institution_id,request_id,digest,response,created_at) VALUES (?,?,?,?,?)', [$i,$request,hash('sha256', $request),'{}',gmdate('c')]);
        $this->s->mutationId = (int)$this->s->db->lastInsertId();
    }

}
