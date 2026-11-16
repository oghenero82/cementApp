<?php

namespace App\Libraries\Cement\Native;

use App\Libraries\Cement\{Catalog,Problem};

/** Native backend. No method calls the local mutation, stock or journal engines. */
final class Backend
{
    private ?array $bootstrap = null;
    public function __construct(private ?Transport $transport = null)
    {
        $this->transport ??= new Transport();
    }
    private function bootstrap(): array
    {
        if ($this->bootstrap !== null) {
            return $this->bootstrap;
        }
        $b = $this->transport->request('GET', 'bootstrap');
        if (($b['authenticated'] ?? false) === true) {
            $cap = $this->transport->request('GET', 'cement/capabilities');
            if (($cap['metadataRevision'] ?? null) !== ($b['metadataRevision'] ?? null) || ($cap['contextRevision'] ?? null) !== ($b['context']['revision'] ?? null)) {
                throw new Problem('MetaForge authorization changed; reconnect', 409);
            }
            $b['cementPermissions'] = $cap['permissions'];
        }
        return $this->bootstrap = $b;
    }
    private function authenticated(): array
    {
        $b = $this->bootstrap();
        if (($b['authenticated'] ?? false) !== true) {
            throw new Problem('Sign in to MetaForge', 401);
        }return $b;
    }
    public function request(string $method, string $path, ?array $body = null): array
    {
        $parts = explode('?', $path, 2);
        $path = $parts[0];
        parse_str($parts[1] ?? '', $query);
        if ($method === 'GET' && $path === 'auth/session') {
            return $this->session($this->bootstrap());
        }
        if ($method === 'POST' && in_array($path, ['auth/login','auth/role','auth/password','auth/logout','auth/reauthenticate'], true)) {
            if ($path === 'auth/password') {
                $body = ['currentPassword' => $body['current'] ?? $body['currentPassword'] ?? '', 'newPassword' => $body['replacement'] ?? $body['newPassword'] ?? '', 'initial' => ($body['initial'] ?? false) === true];
            }
            $result = $this->transport->request('POST', $path, $body ?? []);
            $this->bootstrap = null;
            if ($path === 'auth/logout') {
                return ['signedOut' => true];
            }
            return $this->session($result);
        }
        $bootstrap = $this->authenticated();
        $metadata = (new Metadata())->build($bootstrap);
        if ($method === 'GET') {
            return match($path) {
                'meta/field-registry' => $metadata,
                'portal/navigation' => $this->navigation($bootstrap, $metadata),
                'sync' => $this->sync($query, $metadata),
                'lookups' => $this->lookups($query, $metadata),
                'dashboard' => $this->dashboard($query, $metadata),
                'health' => ['backendMode' => 'metaforge-native','database' => 'remote','registryRevision' => $metadata['revision'],'businessRuntime' => $bootstrap['institution']['jsonData']['settings']['cementRuntime'] ?? 'not_installed'],
                default => throw new Problem('Native MetaForge capability is not installed: '.$path, 501),
            };
        }
        if ($method === 'POST' && $path === 'mutation') {
            return $this->mutate($body ?? [], $metadata);
        }
        throw new Problem('Unsupported native MetaForge capability', 501);
    }
    private function session(array $b): array
    {
        if (($b['authenticated'] ?? false) !== true) {
            $result = ['user' => null,'needsSetup' => false];
            if (isset($b['next'])) {
                $result['next'] = $b['next'];
            }if (isset($b['availableRoles'])) {
                $result['availableRoles'] = array_map(fn ($r) => ['id' => $r['recordId'],'name' => $r['jsonData']['name'] ?? $r['recordCode']], $b['availableRoles']);
            }return $result;
        }
        $code = $b['role']['recordCode'] ?? '';
        $catalog = new Catalog();
        // This display mapping never grants native permissions; mutation/sync re-resolve native grants.
        if ($code === 'developer_role' && ($b['portal']['recordCode'] ?? '') === 'developer_portal') {
            $code = 'cement_system_admin';
        }
        if ($code === 'cement_driver') {
            throw new Problem('Native driver assignment scoping is not installed', 503);
        }
        if (!isset($catalog->roles[$code])) {
            throw new Problem('This MetaForge role is not configured for Cement', 403);
        }
        return ['user' => ['id' => $b['user']['recordId'],'institution_id' => $b['institutionId'],'role' => $code,'display_name' => $b['user']['jsonData']['name'] ?? $b['user']['jsonData']['email'] ?? 'MetaForge user','email' => $b['user']['jsonData']['email'] ?? ''],'needsSetup' => false];
    }
    private function navigation(array $b, array $m): array
    {
        $catalog = new Catalog();
        $modules = [];
        $workspaces = [];
        foreach ($catalog->json('modules') as $module) {
            $module['types'] = [];
            foreach ($m['descriptors'] as $type => $d) {
                if ($d['module'] === $module['code']) {
                    $module['types'][] = ['type' => $type,'title' => $d['title']];
                }
            }if ($module['types']) {
                $modules[] = $module;
            }
        }
        foreach ($catalog->json('workspaces') as $key => $w) {
            if (isset($m['descriptors'][$w['root']])) {
                $w['links'] = array_values(array_filter($w['links'], fn ($link) => isset($m['descriptors'][$link[1]])));
                $w['actions'] = array_values(array_filter($w['actions'], fn ($a) => isset($m['descriptors'][$a[1]]) && $m['descriptors'][$a[1]]['canWrite']));
                $workspaces[$key] = $w;
            }
        }
        return ['modules' => $modules,'workspaces' => $workspaces,'roleTitle' => $b['role']['jsonData']['name'] ?? 'MetaForge','canAdmin' => false,'canFinance' => false,'reports' => [],'notice' => 'MetaForge catalogs are connected. Native purchase, stock, payroll and ledger posting are not yet available.'];
    }
    private function sync(array $query, array $metadata): array
    {
        $state = ['watermarks' => [],'page' => null];
        if (!empty($query['cursor'])) {
            try {
                $state = json_decode(base64_decode($query['cursor'], true) ?: '', true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new Problem('Invalid native sync cursor');
            }if (!is_array($state) || ($state['scope'] ?? null) !== $metadata['scope'] || !is_array($state['watermarks'] ?? null)) {
                throw new Problem('Sync scope changed; request a fresh snapshot', 409);
            }
        }
        $params = ['cursors' => json_encode((object)$state['watermarks'], JSON_THROW_ON_ERROR)];
        if (!empty($state['page'])) {
            $params['cursor'] = $state['page'];
        }
        $native = $this->transport->request('GET', 'sync?'.http_build_query($params));
        if (($native['metadataRevision'] ?? null) !== ($this->bootstrap()['metadataRevision'] ?? null) || ($native['contextRevision'] ?? null) !== ($this->bootstrap()['context']['revision'] ?? null)) {
            throw new Problem('MetaForge schema or authorization changed during synchronization', 409);
        }
        $next = ['scope' => $metadata['scope'],'watermarks' => $native['complete'] ? $native['sync_mutationId'] : $state['watermarks'],'page' => $native['cursor']];
        return ['records' => (new Metadata())->rows($native['records'], $metadata),'cursor' => base64_encode(json_encode($next, JSON_THROW_ON_ERROR)),'afterId' => 0,'hasMore' => !$native['complete'],'scope' => $metadata['scope'],'registryRevision' => $metadata['revision']];
    }
    private function dashboard(array $query, array $metadata): array
    {
        $counts = [];
        $totals = [];
        foreach (['customer_sales','purchase','purchaseItem','inventory_stock','trip'] as $type) {
            if (!isset($metadata['recordTypeIds'][$type])) {
                continue;
            }
            $response = $this->transport->request('GET', 'records?'.http_build_query(['recordTypeId' => $metadata['recordTypeIds'][$type]]));
            $rows = $response['records'] ?? [];
            $counts[$type] = count($rows);
            // Native posting is not certified yet; show counts without manufacturing monetary aggregates.
        }
        return ['counts' => $counts,'totals' => $totals,'trend' => [],'financialPostingAvailable' => false];
    }
    private function lookups(array $q, array $metadata): array
    {
        $type = $q['recordTypeCode'] ?? '';
        $alias = $q['alias'] ?? '';
        $descriptor = $metadata['descriptors'][$type] ?? null;
        if (!$descriptor || !$descriptor['canWrite']) {
            throw new Problem('Lookup is not authorized', 403);
        }
        $target = null;
        foreach ($descriptor['fields'] as $f) {
            if ($f['alias'] === $alias) {
                $target = $f['target'] ?? null;
            }
        }
        if (!$target || !isset($metadata['recordTypeIds'][$target])) {
            throw new Problem('The referenced native schema is not authorized or mapped', 403);
        }
        $native = $this->transport->request('GET', 'records?'.http_build_query(['recordTypeId' => $metadata['recordTypeIds'][$target]]));
        $items = [];
        $map = $metadata['fieldsByRecordType'][$target];
        foreach ($native['records'] ?? [] as $r) {
            $d = $r['jsonData'];
            $label = '';
            foreach (['NAME','COMPANY_NAME','REGISTRATION_NUMBER','CODE'] as $key) {
                if (isset($map[$key]) && !empty($d[$map[$key]])) {
                    $label = (string)$d[$map[$key]];
                    break;
                }
            }$items[] = ['id' => $r['recordId'],'label' => $label ?: '#'.$r['recordId']];
        }return ['items' => $items];
    }
    private function mutate(array $envelope, array $metadata): array
    {
        if (($envelope['registryRevision'] ?? null) !== $metadata['revision']) {
            throw new Problem('Registry revision changed; review this draft before replay', 409);
        }
        if (!is_string($envelope['requestId'] ?? null) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $envelope['requestId'])) {
            throw new Problem('Native mutations require the original UUID request identity');
        }
        foreach ($envelope['batch'] ?? [$envelope] as $entry) {
            $type = $entry['recordTypeCode'] ?? '';
            if (!isset($metadata['descriptors'][$type]) || !$metadata['descriptors'][$type]['canWrite']) {
                throw new Problem('Native write is not authorized', 403);
            }if (($entry['recordTypeId'] ?? null) !== $metadata['recordTypeIds'][$type]) {
                throw new Problem('Native RecordType identity mismatch');
            }
        }
        // A trusted native runtime validates versions/references and produces all financial/stock plans
        // inside MutationProcessor's one transaction and exact request fingerprint.
        $result = $this->transport->request('POST', 'mutations', ['requestId' => $envelope['requestId'],'mode' => 'service_only_mode','service' => ['code' => 'cement.execute','parameters' => ['envelope' => $envelope]]]);
        if (($result['success'] ?? false) !== true) {
            throw new Problem('MetaForge did not confirm this mutation', 502);
        }
        $output = $result['results']['cement.execute'] ?? null;
        if (!is_array($output) || !is_array($output['resultMap'] ?? null)) {
            throw new Problem('Native Cement runtime returned an incompatible result', 502);
        }
        $groups = [];
        foreach ($result['records'] ?? [] as $entry) {
            $groups[$entry['physicalTableId']][] = $entry['record'];
        }
        $changes = (new Metadata())->rows($groups, $metadata);
        return ['state' => 'confirmed','mutationId' => $result['mutationId'],'resultMap' => $output['resultMap'],'results' => $output['results'] ?? [],'changes' => $changes];
    }
}
