<?php

namespace App\Controllers;

use App\Libraries\Cement\{Store,Catalog,Auth,Mutation,Services,Reports,MetaForge,Problem};

final class Api extends BaseController
{
    private Store $store;
    public function dispatch(string ...$segments)
    {
        $path = implode('/', $segments) ?: 'auth/session';
        $path = match($path) {
            'reports/generate' => 'reports','import' => 'imports','import/history' => 'imports',default => $path
        };
        $session = session();
        if (!$session->get('cement_csrf')) {
            $session->set('cement_csrf', bin2hex(random_bytes(32)));
        }
        $this->response->setHeader('Cache-Control', 'no-store')->setHeader('X-Content-Type-Options', 'nosniff');
        try {
            $method = strtoupper($this->request->getMethod());
            if ($method === 'POST' && !hash_equals($session->get('cement_csrf'), $this->request->getHeaderLine('X-Cement-CSRF'))) {
                throw new Problem('Session security token expired. Reload before retrying.', 419);
            }
            $input = in_array($path, ['files/upload','imports/preview']) ? [] : ($method === 'POST' ? ($this->request->getJSON(true) ?? []) : $this->request->getGet());
            if (!is_array($input)) {
                throw new Problem('Expected a JSON object');
            }
            if ((getenv('CEMENT_DRIVER') ?: 'local') === 'metaforge') {
                if ($path === 'auth/session' && (getenv('METAFORGE_PROTOCOL') ?: 'gateway') !== 'native' && !$session->get('metaforge_token')) {
                    return $this->response->setJSON(['user' => null,'needsSetup' => false,'backendMode' => 'metaforge','csrfToken' => $session->get('cement_csrf')]);
                }
                $proxy = new MetaForge();
                if ($method === 'POST' && in_array($path, ['files/upload','imports/preview'])) {
                    return $this->response->setJSON($proxy->upload($this->request, $path));
                }
                if ($method === 'GET' && str_starts_with($path, 'files/')) {
                    $remoteFile = $proxy->download(substr($path, 6));
                    return $this->response->setHeader('Content-Type', 'application/octet-stream')->setHeader('Content-Disposition', 'attachment; filename="cement-attachment"')->setBody($remoteFile->getBody());
                }
                $format = $input['format'] ?? null;
                if ($path === 'reports') {
                    unset($input['format']);
                }
                $remote = $proxy->request($method, $path.($method === 'GET' && $input ? '?'.http_build_query($input) : ''), $method === 'POST' ? $input : null);
                if ($path === 'reports' && in_array($format, ['csv','xlsx'])) {
                    return $this->response->setHeader('Content-Type', $format === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->setHeader('Content-Disposition', 'attachment; filename="cement-report.'.$format.'"')->setBody($format === 'csv' ? Reports::csv($remote) : Reports::xlsx($remote));
                }
                if (in_array($path, ['auth/login','auth/role','auth/password'], true)) {
                    $session->regenerate(true);
                    $session->set('cement_csrf', bin2hex(random_bytes(32)));
                }
                $remote['csrfToken'] = $session->get('cement_csrf');
                $remote['backendMode'] = 'metaforge';
                return $this->response->setJSON($remote);
            }
            $this->store = new Store();
            $this->store->migrate();
            $auth = new Auth($this->store);
            if ($path === 'auth/session' && $method === 'GET') {
                $user = null;
                if ($session->get('cement_user')) {
                    try {
                        $user = $auth->user((int)$session->get('cement_user'));
                    } catch (Problem) {
                        $session->remove('cement_user');
                    }
                }
                return $this->response->setJSON(['user' => $user,'needsSetup' => (int)$this->store->run('SELECT count(*) FROM institutions')->fetchColumn() === 0,'csrfToken' => $session->get('cement_csrf'),'backendMode' => 'local']);
            }
            if (in_array($path, ['auth/setup','auth/login']) && $method === 'POST') {
                $user = $path === 'auth/setup' ? $auth->setup($input) : $auth->login($input['email'] ?? '', $input['password'] ?? '', $this->request->getIPAddress());
                $session->regenerate(true);
                $session->set('cement_user', $user['id']);
                $session->set('cement_csrf', bin2hex(random_bytes(32)));
                return $this->response->setJSON(['user' => $user,'csrfToken' => $session->get('cement_csrf'),'backendMode' => 'local']);
            }
            $actor = $auth->user((int)$session->get('cement_user'));
            $i = (int)$actor['institution_id'];
            $c = $this->store->catalog;
            if ($path === 'auth/logout' && $method === 'POST') {
                $session->destroy();
                return $this->response->setJSON(['signedOut' => true]);
            }
            if ($path === 'auth/password' && $method === 'POST') {
                $hash = $this->store->run('SELECT password FROM users WHERE id=?', [$actor['id']])->fetchColumn();
                if (!password_verify($input['current'] ?? '', $hash) || strlen($input['replacement'] ?? '') < 12) {
                    throw new Problem('Current password and a new password of at least 12 characters are required');
                }
                $this->store->run('UPDATE users SET password=? WHERE id=?', [Auth::hashPassword($input['replacement']),$actor['id']]);
                return $this->response->setJSON(['changed' => true]);
            }
            if ($method === 'GET' && str_starts_with($path, 'files/')) {
                $file = (new \App\Libraries\Cement\Files($this->store))->authorize($actor, substr($path, 6));
                return $this->response->setContentType($file['mime'])->setHeader('Content-Disposition', 'attachment; filename="attachment.'.$file['extension'].'"')->setBody(file_get_contents(\App\Libraries\Cement\Files::filePath($file)));
            }
            $output = match($method.' '.$path) {
                'GET meta/field-registry' => $this->metadata($actor),
                'GET portal/navigation' => $this->navigation($actor),
                'POST mutation' => (new Mutation($this->store))->execute($actor, $input),
                'GET sync' => $this->sync($actor, $input),
                'GET dashboard' => $this->dashboard($actor, $input),
                'POST imports/preview' => (new \App\Libraries\Cement\ImportPreview($c))->upload($actor, $this->request),
                'POST files/upload' => (new \App\Libraries\Cement\Files($this->store))->upload($actor, $this->request),
                'GET lookups' => $this->lookups($actor, $input),
                'GET permissions' => (new \App\Libraries\Cement\Permissions($this->store))->read($actor),
                'POST permissions' => (new \App\Libraries\Cement\Permissions($this->store))->update($actor, $input),
                'GET customers/statement' => (new \App\Libraries\Cement\CustomerStatement($this->store))->generate($actor, $input),
                'GET health' => ['backendMode' => 'local','database' => 'connected','registryRevision' => Catalog::REVISION,'sharedCooperative' => 'local-shared-recordtypes','reportEmail' => getenv('CEMENT_REPORT_EMAIL_ENABLED') === '1' ? 'configured' : 'pending_configuration'],
                'GET reports' => $this->report($actor, $input),
                'GET reports/result' => $this->reportResult($actor, $input),
                'POST reports' => $this->saveReport($actor, $input),
                'GET imports' => $this->imports($actor),
                'POST imports' => $this->import($actor, $input),
                'POST users' => $auth->createUser($actor, $input),
                'GET users' => $this->users($actor),
                'GET settings' => $this->settings($actor),
                'POST settings' => $this->updateSettings($actor, $input),
                'GET ledger' => $this->ledger($actor),
                'GET audit' => $this->audit($actor),
                default => throw new Problem('Endpoint not found', 404),
            };
            if ($method === 'GET' && in_array($path, ['reports','reports/result','customers/statement']) && ($input['format'] ?? '') === 'xlsx') {
                return $this->response->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->setHeader('Content-Disposition', 'attachment; filename="cement-report.xlsx"')->setBody((new Reports($this->store))->xlsx($output));
            }
            if ($method === 'GET' && in_array($path, ['reports','reports/result','customers/statement']) && ($input['format'] ?? '') === 'csv') {
                return $this->response->setHeader('Content-Type', 'text/csv; charset=UTF-8')->setHeader('Content-Disposition', 'attachment; filename="cement-report.csv"')->setBody((new Reports($this->store))->csv($output));
            }
            return $this->response->setJSON($output);
        } catch (Problem $e) {
            return $this->response->setStatusCode($e->httpStatus, $e->httpStatus === 419 ? 'Session Expired' : '')->setJSON(['message' => $e->getMessage(),'fields' => $e->fields]);
        } catch (\Throwable $e) {
            log_message('error', 'Cement API: {error}', ['error' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['message' => 'The operation failed and was not confirmed. Check server logs before retrying.']);
        }
    }
    private function admin(array $actor): void
    {
        if (!in_array($actor['role'], ['cement_admin','cement_system_admin'])) {
            throw new Problem('Administrator access required', 403);
        }
    }
    private function finance(array $actor): void
    {
        if (!in_array($actor['role'], ['cement_admin','cement_system_admin','cement_accountant'])) {
            throw new Problem('Financial access required', 403);
        }
    }
    private function metadata(array $actor): array
    {
        $c = $this->store->catalog;
        $fields = [];
        $ids = [];
        $descriptors = [];
        foreach ($this->store->typeIds() as $type => $id) {
            if ($c->allowed($actor['role'], $type)) {
                $fields[$type] = $c->fields[$type];
                $ids[$type] = (int)$id;
                $descriptors[$type] = ['title' => $c->types[$type]['name'],'module' => $c->types[$type]['module'],'fields' => $c->descriptors($type),'canWrite' => $c->allowed($actor['role'], $type, true),'readOnlyAliases' => array_merge(Services::OWNED[$type] ?? [], in_array($type, ['journal_line','bank_statement_line']) ? [] : array_values(array_intersect(['JOURNAL_ID','POSTED_JOURNAL_ID'], array_keys($c->fields[$type])))),'serviceOwned' => in_array($type, ['inventory_stock','inventory_cost_layer','cement_audit_event','report_result','report_delivery','account_balance'])];
            }
        }
        return ['scope' => (new \App\Libraries\Cement\Scope($this->store))->key($actor),'revision' => Catalog::REVISION,'source' => 'local','currency' => $this->store->settings((int)$actor['institution_id'])['defaultCurrency'],'fieldsByRecordType' => $fields,'recordTypeIds' => $ids,'descriptors' => $descriptors,'authoritative' => true];
    }
    private function navigation(array $actor): array
    {
        $c = $this->store->catalog;
        $role = $c->roles[$actor['role']];
        $workspaces = [];
        foreach ($c->json('workspaces') as $key => $w) {
            if (in_array($w['module'], $role['modules']) && $c->allowed($actor['role'], $w['root'])) {
                $w['links'] = array_values(array_filter($w['links'], fn ($link) => $c->allowed($actor['role'], $link[1])));
                $w['actions'] = array_values(array_filter($w['actions'], fn ($action) => $action[1] && $c->allowed($actor['role'], $action[1], true)));
                $workspaces[$key] = $w;
            }
        }
        $modules = [];
        foreach ($c->json('modules') as $module) {
            if (in_array($module['code'], $role['modules'])) {
                $module['types'] = [];
                foreach ($c->types as $type => $spec) {
                    if ($spec['module'] === $module['code'] && $c->allowed($actor['role'], $type)) {
                        $module['types'][] = ['type' => $type,'title' => $spec['name']];
                    }
                }$modules[] = $module;
            }
        }
        return ['modules' => $modules,'workspaces' => $workspaces,'roleTitle' => $role['name'],'canAdmin' => in_array($actor['role'], ['cement_system_admin','cement_admin']),'canFinance' => in_array($actor['role'], ['cement_system_admin','cement_admin','cement_accountant']),'reports' => array_values(array_filter($c->json('report_templates'), fn ($r) => !array_filter($r['sourceRecordTypes'], fn ($t) => isset($c->types[$t]) && !$c->allowed($actor['role'], $t))))];
    }
    private function sync(array $actor, array $q): array
    {
        $cursor = max(0, (int)($q['cursor'] ?? 0));
        $after = max(0, (int)($q['afterId'] ?? 0));
        $high = (int)$this->store->run('SELECT COALESCE(MAX(id),0) FROM mutations WHERE institution_id=?', [$actor['institution_id']])->fetchColumn();
        $rows = [];
        $raw = $this->store->run('SELECT * FROM records WHERE institution_id=? AND (mutation_id>? OR (mutation_id=? AND id>?)) AND mutation_id<=? ORDER BY mutation_id,id LIMIT 501', [$actor['institution_id'],$cursor,$cursor,$after,$high])->fetchAll(\PDO::FETCH_ASSOC);
        $more = count($raw) > 500;
        $raw = array_slice($raw, 0, 500);
        $ids = $this->store->typeIds();
        foreach ($raw as $r) {
            if ($this->store->catalog->allowed($actor['role'], $r['type'])) {
                $r = $this->store->hydrate($r);
                if (!(new \App\Libraries\Cement\Scope($this->store))->allows($actor, $r['type'], $this->store->catalog->decode($r['type'], $r['data']) + ['_ID' => $r['id']])) {
                    continue;
                }
                $rows[] = ['recordTypeId' => (int)$ids[$r['type']],'recordTypeCode' => $r['type'],'recordId' => $r['id'],'physicalTableId' => 'local','mutationId' => $r['mutation_id'],'version' => $r['version'],'deleted' => (bool)$r['deleted'],'data' => $r['data']];
            }
        }
        $last = end($raw);
        return ['records' => $rows,'cursor' => $more ? (int)$last['mutation_id'] : $high,'afterId' => $more ? (int)$last['id'] : 0,'hasMore' => $more,'scope' => (new \App\Libraries\Cement\Scope($this->store))->key($actor),'registryRevision' => Catalog::REVISION];
    }
    private function lookups(array $actor, array $q): array
    {
        $type = $q['recordTypeCode'] ?? '';
        $alias = $q['alias'] ?? '';
        $c = $this->store->catalog;
        if (!$c->allowed($actor['role'], $type, true)) {
            throw new Problem('Lookup is not authorized', 403);
        }
        $target = null;
        foreach ($c->descriptors($type) as $f) {
            if ($f['alias'] === $alias) {
                $target = $f['target'];
            }
        }
        if (!$target || !isset($c->types[$target])) {
            throw new Problem('Lookup schema is not available', 422);
        }
        $items = [];
        $i = (int)$actor['institution_id'];
        foreach ($this->store->all($i, $target) as $r) {
            $d = $c->decode($target, $r['data']);
            if ($actor['role'] === 'cement_driver') {
                $allowed = false;
                if ($target === 'customer_sales') {
                    foreach ($this->store->related($i, 'trip_destination', 'SALE_ID', $r['id']) as $stop) {
                        if ((new \App\Libraries\Cement\Scope($this->store))->allows($actor, 'trip_destination', $c->decode('trip_destination', $stop['data']))) {
                            $allowed = true;
                        }
                    }
                } elseif ($target === 'person') {
                    $allowed = $r['id'] === (int)($actor['person_id'] ?? 0);
                } else {
                    $allowed = (new \App\Libraries\Cement\Scope($this->store))->allows($actor, $target, $d + ['_ID' => $r['id']]);
                }
                if (!$allowed) {
                    continue;
                }
            }
            $label = $d['NAME'] ?? $d['COMPANY_NAME'] ?? $d['REGISTRATION_NUMBER'] ?? trim(($d['FIRST_NAME'] ?? '').' '.($d['LAST_NAME'] ?? ''));
            $items[] = ['id' => $r['id'],'label' => $label ?: ($d['CODE'] ?? '#'.$r['id'])];
        }
        return ['items' => $items];
    }
    private function dashboard(array $actor, array $filters): array
    {
        $c = $this->store->catalog;
        $i = (int)$actor['institution_id'];
        $totals = [];
        $trend = [];
        $counts = [];
        foreach (['customer_sales','purchase','purchaseItem','inventory_stock','trip'] as $type) {
            if (!$c->allowed($actor['role'], $type)) {
                continue;
            }
            $rows = $this->store->all($i, $type);
            $counts[$type] = count($rows);
            foreach ($rows as $r) {
                $d = $c->decode($type, $r['data']);
                $date = $d['SALE_DATE'] ?? $d['PURCHASE_DATE'] ?? null;
                if ($date && ((!empty($filters['from']) && $date < $filters['from']) || (!empty($filters['to']) && $date > $filters['to']))) {
                    continue;
                }
                foreach (match($type) {
                    'customer_sales' => ['NET_AMOUNT','BALANCE_DUE'],'purchase' => ['NET_AMOUNT','PAID_AMOUNT'],'purchaseItem' => ['ORIGINAL_QUANTITY','LOADED_QTY','CLEARED_QTY'],'inventory_stock' => ['QUANTITY_ON_HAND','TOTAL_VALUE'],default => ['TOTAL_TRIP_COST']
                } as $alias) {
                    if ($type === 'customer_sales' && !in_array($d['STATUS'], ['invoiced','partially_invoiced'])) {
                        continue;
                    }
                    $value = $d[$alias] ?? 0;
                    if ($type === 'customer_sales' && $alias === 'NET_AMOUNT') {
                        $value = $this->store->run('SELECT COALESCE(SUM(amount_minor),0) FROM sale_recognitions WHERE institution_id=? AND sale_id=?', [$i,$r['id']])->fetchColumn() / 100;
                    }
                    $key = $type.'.'.$alias;
                    $totals[$key] = ($totals[$key] ?? 0) + $value;
                }
                if ($type === 'customer_sales') {
                    foreach ($this->store->run('SELECT * FROM sale_recognitions WHERE institution_id=? AND sale_id=?', [$i,$r['id']])->fetchAll(\PDO::FETCH_ASSOC) as $recognition) {
                        $day = substr($recognition['created_at'], 0, 10);
                        if ((!empty($filters['from']) && $day < $filters['from']) || (!empty($filters['to']) && $day > $filters['to'])) {
                            continue;
                        }$trend[$day] = ($trend[$day] ?? 0) + $recognition['amount_minor'] / 100;
                    }
                }
            }
        }
        ksort($trend);
        return ['totals' => $totals,'counts' => $counts,'trend' => array_map(fn ($day, $value) => ['day' => $day,'value' => $value], array_keys($trend), array_values($trend))];
    }
    private function report(array $actor, array $q): array
    {
        return (new Reports($this->store))->generate($actor, $q['code'] ?? '', $q);
    }
    private function reportResult(array $actor, array $q): array
    {
        $i = (int)$actor['institution_id'];
        $record = $this->store->get($i, 'report_result', (int)($q['recordId'] ?? 0));
        $data = $this->store->catalog->decode('report_result', $record['data']);
        if (!$this->store->catalog->allowed($actor['role'], 'report_result') || !(new \App\Libraries\Cement\Scope($this->store))->allows($actor, 'report_result', $data)) {
            throw new Problem('Report snapshot is not authorized', 403);
        }
        $definition = $this->store->semantic($i, 'report', $data['REPORT_ID']);
        $rows = $data['ROWS'] ?? [];
        return ['title' => $definition['NAME'],'generatedAt' => $data['GENERATED_AT'],'rows' => $rows,'columns' => array_keys($rows[0] ?? []),'rowCount' => count($rows),'filters' => $data['PARAMETERS'] ?? []];
    }
    private function saveReport(array $actor, array $input): array
    {
        $result = $this->report($actor, $input);
        $i = (int)$actor['institution_id'];
        $defs = $this->store->all($i, 'report');
        $id = null;
        foreach ($defs as $r) {
            if ($this->store->catalog->decode('report', $r['data'])['CODE'] === $input['code']) {
                $id = $r['id'];
            }
        }
        if (!$id) {
            throw new Problem('Report definition not found');
        }
        $request = $input['requestId'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_-]{8,128}$/', $request)) {
            throw new Problem('requestId is required');
        }
        $this->store->begin();
        try {
            $source = 'report:'.$request;
            $old = $this->store->run('SELECT data FROM records WHERE institution_id=? AND type=? AND business_code=?', [$i,'report_result',$source])->fetchColumn();
            if ($old) {
                $this->store->commit();
                return ['saved' => true];
            }
            $this->store->run('INSERT INTO mutations(institution_id,request_id,digest,response,created_at) VALUES (?,?,?,?,?)', [$i,$source,hash('sha256', json_encode($input)),json_encode(['saved' => true]),gmdate('c')]);
            $this->store->mutationId = (int)$this->store->db->lastInsertId();
            $authorizationSources = [];
            foreach ($this->store->catalog->json('report_templates') as $template) {
                if ($template['code'] === $input['code']) {
                    $authorizationSources = $template['sourceRecordTypes'];
                }
            }$definition = $this->store->semantic($i, 'report', $id);
            $input['authorizationSources'] = $authorizationSources;
            $input['authorizationRoles'] = $definition['ACCESS_ROLES'] ?? [];
            $saved = $this->store->save($i, 'report_result', ['CODE' => $source,'REPORT_ID' => $id,'GENERATED_AT' => $result['generatedAt'],'PARAMETERS' => $input,'TOTALS' => ['rowCount' => $result['rowCount']],'ROWS' => $result['rows'],'FILE_TYPE' => 'csv','STATUS' => 'completed']);
            $this->store->commit();
            return ['saved' => true,'recordId' => $saved['id'],'result' => $result];
        } catch (\Throwable $e) {
            $this->store->rollback();
            throw $e;
        }
    }
    private function users(array $actor): array
    {
        $this->admin($actor);
        return ['users' => $this->store->run('SELECT id,email,role,person_id,display_name,active FROM users WHERE institution_id=?', [$actor['institution_id']])->fetchAll(\PDO::FETCH_ASSOC),'roles' => $this->store->catalog->roles];
    }
    private function settings(array $actor): array
    {
        $this->admin($actor);
        return ['values' => $this->store->settings((int)$actor['institution_id']),'definitions' => $this->store->catalog->json('institution_settings_extension')];
    }
    private function updateSettings(array $actor, array $input): array
    {
        $this->admin($actor);
        $settings = $this->store->settings((int)$actor['institution_id']);
        $definitions = $this->store->catalog->json('institution_settings_extension');
        foreach ($input as $key => $value) {
            $def = $definitions[$key] ?? null;
            if (!$def) {
                throw new Problem('Unknown policy');
            }
            if (isset($def['enum']) && !in_array($value, $def['enum'], true)) {
                throw new Problem('Invalid policy value');
            }
            if ($def['type'] === 'string' && !is_string($value)) {
                throw new Problem('Expected a string policy');
            }
            if ($def['type'] === 'array' && (!is_array($value) || !array_is_list($value))) {
                throw new Problem('Expected a list policy');
            }
            if ($key === 'defaultCurrency' && (!is_string($value) || !preg_match('/^[A-Z]{3}$/D', $value))) {
                throw new Problem('Currency must be a three-letter ISO code');
            }
            if ($def['type'] === 'boolean' && !is_bool($value)) {
                throw new Problem('Expected boolean policy');
            }
            if ($value !== $settings[$key] && in_array($key, ['ownershipTransferPolicy','inventoryValuationMethod','defaultCurrency'])) {
                $count = $this->store->run('SELECT count(*) FROM records WHERE institution_id=? AND type IN (?,?,?,?,?)', [$actor['institution_id'],'cement_loading','inventory_movement','purchase','customer_sales','account'])->fetchColumn();
                if ($count) {
                    throw new Problem('Changing ownership or costing policy requires a controlled migration after transactions exist');
                }
            }
            $settings[$key] = $value;
        }
        $this->store->run('UPDATE institutions SET settings=? WHERE id=?', [json_encode($settings, JSON_THROW_ON_ERROR),$actor['institution_id']]);
        return ['values' => $settings];
    }
    private function ledger(array $actor): array
    {
        $this->finance($actor);
        $total = $this->store->run('SELECT COALESCE(SUM(amount_minor),0) FROM journals WHERE institution_id=?', [$actor['institution_id']])->fetchColumn() / 100;
        return ['totalDebits' => $total,'totalCredits' => $total,'difference' => 0];
    }
    private function audit(array $actor): array
    {
        $this->admin($actor);
        return ['events' => $this->store->run('SELECT actor_id,request_id,event,created_at FROM audit WHERE institution_id=? ORDER BY id DESC LIMIT 200', [$actor['institution_id']])->fetchAll(\PDO::FETCH_ASSOC)];
    }
    private function imports(array $actor): array
    {
        $this->admin($actor);
        return ['uploads' => $this->store->run('SELECT id,period,filename,digest,source_file_digest,outcomes,created_at FROM uploads WHERE institution_id=? ORDER BY id DESC', [$actor['institution_id']])->fetchAll(\PDO::FETCH_ASSOC)];
    }
    private function import(array $actor, array $input): array
    {
        return (new \App\Libraries\Cement\Importer($this->store))->run($actor, $input);
    }
}
