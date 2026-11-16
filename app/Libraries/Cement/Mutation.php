<?php

namespace App\Libraries\Cement;

final class Mutation
{
    private Catalog $c;
    private Services $services;
    public function __construct(private Store $s)
    {
        $this->c = $s->catalog;
        $this->services = new Services($s);
    }
    public function execute(array $actor, array $envelope): array
    {
        $institution = (int)$actor['institution_id'];
        $request = $envelope['requestId'] ?? '';
        if (!is_string($request) || !preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D', $request)) {
            throw new Problem('A stable requestId is required');
        }
        if (($envelope['registryRevision'] ?? null) !== Catalog::REVISION) {
            throw new Problem('Registry revision changed; review this draft before replay', 409);
        }
        $batch = $envelope['batch'] ?? [$envelope];
        if (!is_array($batch) || !$batch || count($batch) > 100) {
            throw new Problem('Batch must contain 1–100 records');
        }
        foreach ($batch as $entry) {
            if (!is_array($entry) || !$this->c->allowed($actor['role'], $entry['recordTypeCode'] ?? '', true)) {
                throw new Problem('You cannot modify this RecordType', 403);
            }
        }
        $digest = hash('sha256', json_encode($envelope, JSON_THROW_ON_ERROR));
        $this->s->begin();
        try {
            $previous = $this->s->run('SELECT * FROM mutations WHERE institution_id=? AND request_id=?', [$institution,$request])->fetch(\PDO::FETCH_ASSOC);
            if ($previous) {
                if ($previous['digest'] !== $digest) {
                    throw new Problem('requestId was already used for a different payload', 409);
                }
                $this->s->commit();
                return json_decode($previous['response'], true, 512, JSON_THROW_ON_ERROR);
            }
            $this->s->run('INSERT INTO mutations(institution_id,request_id,digest,created_at) VALUES (?,?,?,?)', [$institution,$request,$digest,gmdate('c')]);
            $this->s->mutationId = (int)$this->s->db->lastInsertId();
            $result = [];
            $refs = [];
            foreach ($batch as $entry) {
                $type = $entry['recordTypeCode'] ?? '';
                if (!$this->c->allowed($actor['role'], $type, true)) {
                    throw new Problem('You cannot modify this RecordType', 403);
                }
                if (isset($entry['recordTypeId']) && (int)$entry['recordTypeId'] !== (int)($this->s->typeIds()[$type] ?? 0)) {
                    throw new Problem('RecordType code and ID mismatch');
                }
                if (in_array($type, ['inventory_stock','inventory_cost_layer','cement_audit_event','report_result','report_delivery','account_balance'])) {
                    throw new Problem('This record is service-owned');
                }
                $op = $entry['operation'] ?? 'insert';
                if (!in_array($op, ['insert','update','delete'])) {
                    throw new Problem('Unsupported mutation operation');
                }
                $id = isset($entry['recordId']) ? (int)$entry['recordId'] : null;
                if ($op !== 'insert' && !$id) {
                    throw new Problem('recordId required');
                }
                if ($op === 'insert' && $id) {
                    throw new Problem('Insert cannot choose a record ID');
                }
                $old = $id ? $this->s->get($institution, $type, $id) : null;
                if ($old && (!isset($entry['version']) || (int)$entry['version'] !== $old['version'])) {
                    throw new Problem('Record changed; reload before saving', 409);
                }
                $semantic = $this->c->decode($type, $entry['data'] ?? []);
                foreach ($semantic as &$value) {
                    if (is_array($value) && array_keys($value) === ['$ref']) {
                        if (!isset($refs[$value['$ref']])) {
                            throw new Problem('Batch reference must point to an earlier record');
                        }
                        $value = $refs[$value['$ref']];
                    }
                } unset($value);
                $before = $old ? $this->c->decode($type, $old['data']) : [];
                $data = array_replace($before, $semantic);
                $this->services->guard($actor, $type, $data, $before, $semantic, $op);
                if ($op === 'delete') {
                    foreach ($this->c->types as $other => $spec) {
                        foreach ($this->c->descriptors($other) as $f) {
                            if (($f['target'] ?? null) === $type && $this->s->related($institution, $other, $f['alias'], $id)) {
                                throw new Problem('Referenced records cannot be deleted');
                            }
                        }
                    }
                    $saved = $this->s->save($institution, $type, $before, $id, true);
                    $this->services->deleted($actor, $type, $before);
                } else {
                    $data = $this->services->prepare($actor, $type, $data, $before);
                    $this->validate($institution, $type, $data, $id);
                    $saved = $this->s->save($institution, $type, $data, $id);
                    (new Files($this->s))->bind($actor, $type, $saved['id'], $data);
                    $this->services->after($actor, $type, $saved['id'], $data, $before);
                    $saved = $this->s->get($institution, $type, $saved['id']);
                }
                if (isset($entry['clientRef'])) {
                    $refs[$entry['clientRef']] = $saved['id'];
                }
                $result[] = ['recordTypeCode' => $type,'recordId' => $saved['id']];
                $this->s->run('INSERT INTO audit(institution_id,actor_id,request_id,event,created_at) VALUES (?,?,?,?,?)', [$institution,$actor['id'],$request,json_encode(['operation' => $op,'type' => $type,'id' => $saved['id'],'before' => $before,'after' => $data], JSON_THROW_ON_ERROR),gmdate('c')]);
            }
            $changes = [];
            $typeIds = $this->s->typeIds();
            foreach ($this->s->run('SELECT * FROM records WHERE institution_id=? AND mutation_id=?', [$institution,$this->s->mutationId])->fetchAll(\PDO::FETCH_ASSOC) as $changed) {
                if (!$this->c->allowed($actor['role'], $changed['type'])) {
                    continue;
                }
                $changed = $this->s->hydrate($changed);
                $changes[] = ['recordTypeId' => (int)$typeIds[$changed['type']],'recordTypeCode' => $changed['type'],'recordId' => $changed['id'],'physicalTableId' => 'local','mutationId' => $changed['mutation_id'],'version' => $changed['version'],'deleted' => (bool)$changed['deleted'],'data' => $changed['data']];
            }
            $response = ['mutationId' => $this->s->mutationId,'results' => $result,'resultMap' => $refs,'state' => 'confirmed','changes' => $changes];
            $this->s->run('UPDATE mutations SET response=? WHERE id=?', [json_encode($response, JSON_THROW_ON_ERROR),$this->s->mutationId]);
            $this->s->commit();
            return $response;
        } catch (\Throwable $e) {
            $this->s->rollback();
            if ($e instanceof \PDOException && str_contains($e->getMessage(), 'UNIQUE')) {
                throw new Problem('A record with these unique values already exists', 409);
            }
            throw $e;
        }
    }
    public function validate(int $institution, string $type, array $data, ?int $id): void
    {
        $errors = [];
        foreach ($this->c->descriptors($type) as $f) {
            $alias = $f['alias'];
            $value = $data[$alias] ?? null;
            if ($f['required'] && ($value === null || $value === '')) {
                $errors[$this->c->fields[$type][$alias]] = 'Required';
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $valid = match($f['type']) {
                'string' => is_string($value) && strlen($value) <= 10000,
                'number' => (is_int($value) || is_float($value)) && is_finite((float)$value) && abs($value) <= 1e12,
                'integer','reference' => is_int($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) || is_object($value),
                'date','datetime' => is_string($value) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}/', $value) && strtotime($value) !== false,
                default => false,
            };
            if (!$valid) {
                $errors[$this->c->fields[$type][$alias]] = 'Expected '.$f['type'];
                continue;
            }
            if ($f['type'] === 'reference') {
                if (!isset($this->c->types[$f['target']])) {
                    throw new Problem('Shared '.$f['target'].' integration is not configured; this reference cannot be accepted');
                }
                $this->s->get($institution, $f['target'], $value);
            }
        }
        if ($errors) {
            throw new Problem('Please correct the highlighted fields', 422, $errors);
        }
        foreach ($this->c->types[$type]['uniques'] ?? [] as $keys) {
            $aliases = array_map(fn ($key) => array_search($key, $this->c->fields[$type], true), $keys);
            if (array_filter($aliases, fn ($a) => !isset($data[$a]) || $data[$a] === '')) {
                continue;
            }
            foreach ($this->s->all($institution, $type) as $r) {
                if ($r['id'] === $id) {
                    continue;
                }
                $other = $this->c->decode($type, $r['data']);
                if (count(array_filter($aliases, fn ($a) => (is_int($data[$a]) || is_float($data[$a])) && (is_int($other[$a] ?? null) || is_float($other[$a] ?? null)) ? (float)$data[$a] === (float)$other[$a] : ($data[$a] ?? null) === ($other[$a] ?? null))) === count($aliases)) {
                    throw new Problem('Duplicate unique record', 409);
                }
            }
        }
    }
}
