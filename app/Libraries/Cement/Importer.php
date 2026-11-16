<?php

namespace App\Libraries\Cement;

final class Importer
{
    public function __construct(private Store $s)
    {
    }
    public function run(array $actor, array $input): array
    {
        if (!in_array($actor['role'], ['cement_admin','cement_system_admin'])) {
            throw new Problem('Administrator access required', 403);
        }
        $rows = $input['rows'] ?? [];
        $type = $input['recordTypeCode'] ?? '';
        $revision = $input['registryRevision'] ?? null;
        $period = $input['period'] ?? '';
        if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows) > 100 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period)) {
            throw new Problem('Supply 1–100 rows and a valid YYYY-MM upload period');
        }
        if (!$this->s->catalog->allowed($actor['role'], $type, true)) {
            throw new Problem('Import target is not authorized', 403);
        }
        $source = $input['sourceFileDigest'] ?? null;
        if ($source !== null && (!is_string($source) || !preg_match('/^[a-f0-9]{64}$/D', $source))) {
            throw new Problem('Invalid source file digest');
        }
        $filename = basename($input['filename'] ?? 'import.csv');
        $digest = hash('sha256', json_encode([$type,$revision,$rows], JSON_THROW_ON_ERROR));
        $i = (int)$actor['institution_id'];
        $old = $this->s->run('SELECT outcomes FROM uploads WHERE institution_id=? AND digest=? AND period=?', [$i,$digest,$period])->fetchColumn();
        $prior = $old ? json_decode($old, true) : [];
        $outcomes = [];
        foreach ($rows as $n => $row) {
            if (($prior[$n]['state'] ?? null) === 'confirmed') {
                $outcomes[] = $prior[$n];
                continue;
            }
            try {
                if (!is_array($row)) {
                    throw new Problem('Row must be an object');
                }
                $d = $this->s->catalog->decode($type, $row);
                if ($type === 'payroll_item') {
                    $batch = $this->s->semantic($i, 'payroll', (int)($d['PAYROLL_ID'] ?? 0));
                    if (substr($batch['PERIOD_START'], 0, 7) !== $period) {
                        throw new Problem('Payroll row does not belong to the selected upload month');
                    }
                }
                if ($type === 'payroll' && substr($d['PERIOD_START'] ?? '', 0, 7) !== $period) {
                    throw new Problem('Payroll batch does not belong to the selected upload month');
                }
                $result = (new Mutation($this->s))->execute($actor, ['requestId' => 'import-'.hash('sha256', $digest.':'.$period.':'.$n),'registryRevision' => $revision,'recordTypeCode' => $type,'operation' => 'insert','data' => $row]);
                $outcomes[] = ['row' => $n + 1,'state' => 'confirmed','result' => $result];
            } catch (Problem $e) {
                $outcomes[] = ['row' => $n + 1,'state' => 'failed','message' => $e->getMessage(),'fields' => $e->fields];
            }
        }
        $this->s->run('INSERT INTO uploads(institution_id,actor_id,period,filename,digest,source_file_digest,outcomes,created_at) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(institution_id,digest,period) DO UPDATE SET outcomes=excluded.outcomes', [$i,$actor['id'],$period,$filename,$digest,$source,json_encode($outcomes, JSON_THROW_ON_ERROR),gmdate('c')]);
        return ['outcomes' => $outcomes,'digest' => $digest,'sourceFileDigest' => $source,'replayed' => (bool)$old];
    }
}
