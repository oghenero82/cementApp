<?php

namespace App\Libraries\Cement\Native;

use App\Libraries\Cement\{Catalog, Problem, Services};

/** Only explicit semantic declarations in authorized native metadata become field mappings. */
final class Metadata
{
    public function build(array $bootstrap): array
    {
        $catalog = new Catalog();
        $maps = [];
        $ids = [];
        $descriptors = [];
        $nativeTypes = [];
        $read = [];
        foreach ($bootstrap['cementPermissions'] ?? [] as $grant) {
            if (($grant['allowed'] ?? false) === true && ($grant['action'] ?? null) === 'read') {
                $read[$grant['rtid']] = true;
            }
        }
        $role = $bootstrap['role']['recordCode'] ?? '';
        $privateAllowed = in_array($role, ['cement_admin','cement_system_admin','cement_accountant'], true) || ($role === 'developer_role' && ($bootstrap['portal']['recordCode'] ?? '') === 'developer_portal');
        foreach ($bootstrap['recordTypes'] ?? [] as $row) {
            $code = $row['recordCode'] ?? null;
            if (!is_string($code) || !isset($catalog->types[$code])) {
                continue;
            }
            if (!isset($read[$row['recordId'] ?? 0]) || ($catalog->isPrivate($code) && !$privateAllowed)) {
                continue;
            }
            if (isset($nativeTypes[$code])) {
                throw new Problem('Duplicate native RecordType code', 502);
            }
            $nativeTypes[$code] = $row;
        }
        foreach ($nativeTypes as $type => $row) {
            $definition = $row['jsonData'] ?? [];
            $aliases = $definition['settings']['cementFieldAliases'] ?? null;
            if (!is_array($aliases)) {
                continue;
            }
            $nativeFields = [];
            foreach ($definition['fields'] ?? [] as $field) {
                if (!is_array($field) || !is_string($field['code'] ?? null) || isset($nativeFields[$field['code']])) {
                    throw new Problem('Invalid native field declaration', 502);
                }$nativeFields[$field['code']] = $field;
            }
            $maps[$type] = $aliases;
            $ids[$type] = $row['recordId'] ?? null;
            $fields = [];
            $readonly = [];
            foreach ($catalog->descriptors($type) as $field) {
                $actual = $aliases[$field['alias']] ?? null;
                if (!is_string($actual) || !isset($nativeFields[$actual])) {
                    throw new Problem('Missing declared native field for '.$type.'.'.$field['alias'], 502);
                }
                $native = $nativeFields[$actual];
                $properties = $native['properties'] ?? [];
                $expected = $field['type'];
                $actualType = $native['type'] ?? null;
                if ($actualType !== $expected && !($expected === 'reference' && $actualType === 'integer')) {
                    throw new Problem('Native field type is incompatible for '.$type.'.'.$field['alias'], 502);
                }
                $field['required'] = ($properties['required'] ?? false) === true;
                $fields[] = $field;
                if (($properties['readOnly'] ?? false) === true) {
                    $readonly[] = $field['alias'];
                }
            }
            // A server field that the application cannot supply must never be silently discarded.
            foreach ($nativeFields as $code => $field) {
                if (($field['properties']['required'] ?? false) && !in_array($code, $aliases, true) && !array_key_exists('default', $field) && !isset($field['derived'])) {
                    throw new Problem('Unmapped required native field in '.$type, 502);
                }
            }
            $canWrite = false;
            foreach ($bootstrap['cementPermissions'] ?? [] as $grant) {
                if (($grant['rtid'] ?? null) === $ids[$type] && in_array($grant['action'] ?? null, ['insert','update','delete'], true) && ($grant['allowed'] ?? false) === true) {
                    $canWrite = true;
                }
            }
            $canWrite = $canWrite && in_array('insert', $definition['settings']['cementOperations'] ?? [], true);
            $descriptors[$type] = ['title' => $definition['name'] ?? $catalog->types[$type]['name'],'module' => $catalog->types[$type]['module'],'fields' => $fields,'canWrite' => $canWrite,'readOnlyAliases' => array_values(array_unique(array_merge($readonly, Services::OWNED[$type] ?? []))),'allowedStates' => (isset($definition['settings']['cementPurchaseFunding']) && $privateAllowed) ? ['draft','paid'] : (isset($definition['settings']['cementPurchaseDrafts']) ? ['draft'] : null),'postingAvailable' => isset($definition['settings']['cementPurchaseFunding']) && $privateAllowed,'serviceOwned' => in_array($type, ['inventory_stock','inventory_cost_layer','account_balance','report_result','report_delivery','cement_audit_event'], true)];
        }
        if (!$maps) {
            throw new Problem('No authorized Cement schemas are installed in MetaForge', 502);
        }
        ksort($maps);
        ksort($ids);
        $revision = 'native:'.hash('sha256', json_encode([$bootstrap['metadataRevision'] ?? null,$maps,$ids], JSON_THROW_ON_ERROR));
        $scope = 'native:'.hash('sha256', json_encode([$bootstrap['institutionId'] ?? null,$bootstrap['context']['revision'] ?? null,$bootstrap['roleId'] ?? null,$revision], JSON_THROW_ON_ERROR));
        return $catalog->validateMetadata(['revision' => $revision,'scope' => $scope,'source' => 'metaforge-native','currency' => $bootstrap['institution']['jsonData']['settings']['defaultCurrency'] ?? 'NGN','fieldsByRecordType' => $maps,'recordTypeIds' => $ids,'descriptors' => $descriptors]);
    }
    public function rows(array $groups, array $metadata): array
    {
        $byId = array_flip($metadata['recordTypeIds']);
        $result = [];
        foreach ($groups as $physical => $rows) {
            if (!is_array($rows)) {
                throw new Problem('Invalid native sync group', 502);
            }foreach ($rows as $row) {
                $type = $byId[$row['recordTypeId'] ?? 0] ?? null;
                if ($type === null) {
                    continue;
                }
                $id = $row['recordId'] ?? null;
                $mutation = $row['mutationId'] ?? null;
                $data = $row['jsonData'] ?? null;
                if (is_array($data)) {
                    $data = array_intersect_key($data, array_flip($metadata['fieldsByRecordType'][$type]));
                    foreach ($metadata['descriptors'][$type]['fields'] as $field) {
                        $code = $metadata['fieldsByRecordType'][$type][$field['alias']];
                        if ($field['type'] === 'number' && isset($data[$code]) && is_string($data[$code])) {
                            if (!preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $data[$code]) || !is_finite((float)$data[$code])) {
                                throw new Problem('Native numeric field is incompatible', 502);
                            }
                            $data[$code] = (float)$data[$code];
                        }
                    }
                }
                if (!is_int($id) || $id <= 0 || !is_int($mutation) || $mutation < 0 || !is_array($data) || array_diff(array_keys($data), array_values($metadata['fieldsByRecordType'][$type]))) {
                    throw new Problem('Native sync row violates the active schema', 502);
                }
                $result[] = ['recordTypeId' => $row['recordTypeId'],'recordTypeCode' => $type,'recordId' => $id,'physicalTableId' => (string)$physical,'mutationId' => $mutation,'version' => max(1, $mutation),'deleted' => ($row['isDeleted'] ?? false) == true,'data' => $data];
            }
        }return $result;
    }
}
