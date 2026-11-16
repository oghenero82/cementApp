<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use App\Libraries\Cement\Store;

/** Idempotent local metadata/bootstrap import; never runs the MetaForge catalog installer. */
final class CementAppSeeder extends Seeder
{
    public function run()
    {
        $s = new Store();
        $s->migrate();
        foreach ($s->run('SELECT id FROM institutions')->fetchAll(\PDO::FETCH_COLUMN) as $institution) {
            $i = (int)$institution;
            $known = [];
            foreach ($s->all($i, 'report') as $record) {
                $known[] = $s->catalog->decode('report', $record['data'])['CODE'];
            }
            $missing = array_values(array_filter($s->catalog->json('report_templates'), fn ($r) => !in_array($r['code'], $known)));
            if (!$missing) {
                continue;
            }$s->begin();
            try {
                $request = 'bootstrap-reports:'.bin2hex(random_bytes(8));
                $s->run('INSERT INTO mutations(institution_id,request_id,digest,response,created_at) VALUES (?,?,?,?,?)', [$i,$request,hash('sha256', $request),'{}',gmdate('c')]);
                $s->mutationId = (int)$s->db->lastInsertId();
                foreach ($missing as $report) {
                    $s->save($i, 'report', ['CODE' => $report['code'],'NAME' => $report['name'],'MODULE_CODE' => $report['module'],'GENERATION_MODE' => 'server_stored','SOURCE_RECORD_TYPES' => $report['sourceRecordTypes'],'OUTPUT_FORMATS' => ['csv','xlsx','pdf'],'IS_ACTIVE' => true,'STATUS' => 'active']);
                }$s->commit();
            } catch (\Throwable $e) {
                $s->rollback();
                throw $e;
            }
        }
    }
}
