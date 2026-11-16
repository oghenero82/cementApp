<?php

namespace App\Libraries\Cement;

final class Catalog
{
    public const REVISION = 'cement-seed-2026-11-16';
    public array $fields;
    public array $types = [];
    public array $roles;
    public array $permissionOverrides = [];
    public string $permissionRevision = "initial";

    public function __construct()
    {
        $this->fields = array_replace($this->json('semantic_field_registry'), $this->json('shared_semantic_field_registry'));
        foreach (array_merge($this->json('recordtypes'), $this->json('shared_recordtypes')) as $type) {
            $this->types[$type['code']] = $type;
        }
        $this->roles = $this->json('role_templates');
    }

    public function json(string $file): array
    {
        return json_decode(file_get_contents(ROOTPATH . 'resources/cement/' . $file . '.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function encode(string $type, array $data): array
    {
        $result = [];
        foreach ($data as $alias => $value) {
            if (!isset($this->fields[$type][$alias])) {
                throw new Problem("Unknown semantic alias $type.$alias");
            }
            $result[$this->fields[$type][$alias]] = $value;
        }
        return $result;
    }

    public function decode(string $type, array $data): array
    {
        $map = array_flip($this->fields[$type] ?? []);
        $result = [];
        foreach ($data as $key => $value) {
            if (!isset($map[$key])) {
                throw new Problem("Unknown field in $type", 422, [$key => 'Unknown field']);
            }
            $result[$map[$key]] = $value;
        }
        return $result;
    }

    public function isPrivate(string $type): bool
    {
        return str_starts_with($type, 'payroll') || in_array($type, ['staff_employment', 'staff_deduction', 'person', 'cement_audit_event','member','member_account','cooperative_transaction','account','account_type','journal','journal_line','account_balance','bank_account','bank_statement','bank_statement_line','bank_reconciliation','fiscal_year']);
    }
    public function allowed(string $role, string $type, bool $write = false): bool
    {
        $policy = $this->roles[$role] ?? null;
        if (!$policy || !isset($this->types[$type])) {
            return false;
        }
        // Payroll and credentials are private even when a seed role uses a wildcard read template.
        $private = $this->isPrivate($type);
        if ($private && !in_array($role, ['cement_admin', 'cement_system_admin', 'cement_accountant'])) {
            return false;
        }
        if (isset($this->permissionOverrides[$role][$type])) {
            return $this->permissionOverrides[$role][$type][$write ? 'write' : 'read'];
        }
        if ($role === 'cement_accountant' && $write && in_array($type, ['account_type','account','fiscal_year','bank_account','bank_statement','bank_statement_line','bank_reconciliation','journal','journal_line','member','member_account','cooperative_transaction'])) {
            return true;
        }
        if ($type === 'inventory_reservation' && in_array($role, ['cement_sales_manager','cement_store_keeper'])) {
            return true;
        }
        $list = $policy[$write ? 'can_write' : 'can_read'];
        return in_array('*', $list) || in_array($type, $list);
    }

    public function descriptors(string $type): array
    {
        $inverse = array_flip($this->fields[$type]);
        return array_map(static function ($f) use ($inverse) {
            $f['alias'] = $inverse[$f['code']];
            unset($f['code']);
            return $f;
        }, $this->types[$type]['fields']);
    }
    public function validateMetadata(array $metadata): array
    {
        if (!is_string($metadata['revision'] ?? null) || !trim($metadata['revision']) || !is_array($metadata['fieldsByRecordType'] ?? null) || !is_array($metadata['recordTypeIds'] ?? null)) {
            throw new Problem('MetaForge returned an invalid metadata envelope', 502);
        }
        $ids = [];
        foreach ($metadata['fieldsByRecordType'] as $type => $mapping) {
            if (!isset($this->fields[$type]) || !is_array($mapping)) {
                throw new Problem('Unsupported MetaForge semantic schema', 502);
            }
            if (array_diff(array_keys($this->fields[$type]), array_keys($mapping)) || array_diff(array_keys($mapping), array_keys($this->fields[$type]))) {
                throw new Problem('MetaForge semantic aliases do not match the application contract', 502);
            }
            $values = [];
            foreach ($mapping as $alias => $code) {
                if (!is_string($code) || !trim($code) || isset($values[$code])) {
                    throw new Problem('MetaForge metadata contains blank or colliding codes', 502);
                }$values[$code] = true;
            }
            $id = $metadata['recordTypeIds'][$type] ?? null;
            if (!is_int($id) || $id <= 0 || isset($ids[$id])) {
                throw new Problem('MetaForge RecordType IDs are invalid', 502);
            }$ids[$id] = true;
        }
        if (array_diff(array_keys($metadata['recordTypeIds']), array_keys($metadata['fieldsByRecordType']))) {
            throw new Problem('MetaForge returned unauthorized ID mappings', 502);
        }
        $metadata['authoritative'] = true;
        return $metadata;
    }

}
