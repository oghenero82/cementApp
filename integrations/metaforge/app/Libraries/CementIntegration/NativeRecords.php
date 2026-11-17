<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\{Metadata as M,FieldType};
use App\Libraries\MetaForge\Database\RecordRepository;
use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Institution-scoped access and semantic encoding for verified Cement handlers. */
final class NativeRecords
{
    public function __construct(public readonly MutationContext $context)
    {
    }
    public function meta(string $type, string $action = 'read'): array
    {
        return $this->context->type(M::id($this->context->catalog->metadata($type)), $action);
    }
    public function aliases(string $type): array
    {
        return M::properties($this->context->catalog->metadata($type))['settings']['cementFieldAliases'];
    }
    public function decode(string $type, array $data): array
    {
        $r = [];
        foreach ($this->aliases($type) as $a => $code) {
            if (array_key_exists($code, $data)) {
                $r[$a] = $data[$code];
            }
        }return $r;
    }
    public function encode(string $type, array $data): array
    {
        $r = [];
        $map = $this->aliases($type);
        foreach ($data as $a => $value) {
            if (!isset($map[$a])) {
                throw new \InvalidArgumentException('Unknown semantic field: '.$type.'.'.$a);
            }$r[$map[$a]] = is_float($value) ? FieldType::numericString($value) : $value;
        }return $r;
    }
    public function rows(string $type): array
    {
        $m = $this->meta($type);
        $repo = new RecordRepository(\Config\Database::connect());
        return array_map(fn ($row) => $this->decode($type, M::properties($row)) + ['_ID' => M::id($row),'_MUTATION' => M::physical($row, 'mutationId', 0),'_PRIVATE' => M::properties($row)['settings']['cement'] ?? []], $repo->query($repo->table($this->context, $m), M::id($m)));
    }
    public function raw(string $type, int $id): array
    {
        $m = $this->meta($type);
        $repo = new RecordRepository(\Config\Database::connect());
        $row = $repo->find($repo->table($this->context, $m), M::id($m), $id, true);
        if (!$row || M::physical($row, 'isDeleted', 0)) {
            throw ValidationException::field('reference', 'Record is unavailable in this institution');
        }return M::properties($row);
    }
    public function find(string $type, int $id): array
    {
        $m = $this->meta($type);
        $repo = new RecordRepository(\Config\Database::connect());
        $row = $repo->find($repo->table($this->context, $m), M::id($m), $id, true);
        if (!$row || M::physical($row, 'isDeleted', 0)) {
            throw ValidationException::field('reference', 'Record is unavailable in this institution');
        }return $this->decode($type, M::properties($row)) + ['_ID' => $id,'_MUTATION' => M::physical($row, 'mutationId', 0),'_PRIVATE' => M::properties($row)['settings']['cement'] ?? []];
    }
    public function plan(string $type, array $data, ?int $id = null, ?array $private = null): array
    {
        $op = $id ? 'update' : 'insert';
        $p = ['recordTypeId' => M::id($this->meta($type, $op)),'operation' => $op,'data' => $this->encode($type, $data)];
        if ($private !== null) {
            $p['data']['settings'] = ['cement' => $private];
        }
        if ($id) {
            $p['recordId'] = $id;
        }return $p;
    }
    public function actorPerson(): ?int
    {
        $user = (new \App\Libraries\MetaForge\Auth\AuthRepository(\Config\Database::connect()))->user($this->context->auth['user']['recordId']);
        $person = M::properties($user)['settings']['cementPersonId'] ?? null;
        if ($person === null) {
            return null;
        }if (!is_int($person) || $person <= 0) {
            throw ValidationException::field('person', 'Configure a valid linked staff person');
        }$this->find('person', $person);
        return $person;
    }
    public function settings(): array
    {
        return M::properties($this->context->catalog->record('institution', $this->context->auth['institutionId']))['settings'] ?? [];
    }
}
