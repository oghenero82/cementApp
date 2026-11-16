<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Database\RecordRepository;
use App\Libraries\MetaForge\Mutation\MutationContext;
use App\Libraries\MetaForge\System\keys\MutationKeys as K;

/** Native plans only. SQL writes and transaction ownership remain with MutationProcessor. */
final class CatalogService
{
    public const MASTERS = ['cement_branch','supplier','supplier_depot','supplier_product','customer','product_category','product','unit_of_measure','price_list','price_list_item','inventory_location','person','vehicle','staff_employment'];
    public static function register(\App\Libraries\MetaForge\Mutation\Services\ServiceRegistry $registry): void
    {
        $handler = new self();
        $registry->register('cement.catalog.guard', $handler, 'validate', [
            ['name' => 'recordTypeCode','type' => 'string'],['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object']]);
        $registry->register(
            'cement.execute',
            $handler,
            'execute',
            [['name' => 'envelope','type' => 'object']],
            [],
            static function (MutationContext $context): bool {
                foreach ($context->auth['recordTypes'] as $meta) {
                    if (isset(M::properties($meta)['settings']['cementFieldAliases'])) {
                        return true;
                    }
                }
                return false;
            }
        );
    }
    public function validate(string $recordTypeCode, array $record, array $previous, MutationContext $context): array
    {
        if (!in_array($recordTypeCode, self::MASTERS, true)) {
            return [K::SERVICE_VALIDATION_ERRORS => [['fieldPath' => 'status','code' => 'cement_posting_unavailable','message' => 'This Cement transaction requires its verified native business runtime; no stock or ledger effect was posted.']]];
        }
        $catalog = $context->catalog;
        $meta = $catalog->metadata($recordTypeCode);
        $definition = M::properties($meta);
        $aliases = $definition['settings']['cementFieldAliases'];
        $source = json_decode(file_get_contents(ROOTPATH.'resources/cement/recordtypes.json'), true, 64, JSON_THROW_ON_ERROR);
        $source = array_column($source, null, 'code');
        $repo = new RecordRepository(\Config\Database::connect());
        foreach ($source[$recordTypeCode]['fields'] as $field) {
            if ($field['target'] !== null) {
                $alias = array_search($field['code'], json_decode(file_get_contents(ROOTPATH.'resources/cement/semantic_field_registry.json'), true, 64, JSON_THROW_ON_ERROR)[$recordTypeCode], true);
                $value = $record[$aliases[$alias]] ?? null;
                if ($value === null) {
                    continue;
                }
                $target = $catalog->metadata($field['target']);
                $target = $context->type(M::id($target), 'read');
                $row = $repo->find($repo->table($context, $target), M::id($target), $value);
                if (!$row || M::physical($row, 'isDeleted', 0)) {
                    throw new \RuntimeException('Referenced record is not available in this institution.');
                }
            }
        }
        return [];
    }
    public function execute(array $envelope, MutationContext $context): array
    {
        if (!is_string($envelope['requestId'] ?? null)) {
            throw new \InvalidArgumentException('Original request identity required');
        }
        $maps = [];
        $ids = [];
        $known = json_decode(file_get_contents(ROOTPATH.'resources/cement/semantic_field_registry.json'), true, 64, JSON_THROW_ON_ERROR);
        $read = [];
        foreach ($context->auth['permissions'] as $grant) {
            if (($grant['allowed'] ?? false) === true && ($grant['action'] ?? null) === 'read' && isset($grant['rtid'])) {
                $read[$grant['rtid']] = true;
            }
        }
        $role = (new \App\Libraries\MetaForge\Auth\AuthRepository(\Config\Database::connect()))->role($context->auth['roleId']);
        $roleCode = M::code($role);
        $privateAllowed = in_array($roleCode, ['developer_role','cement_admin','cement_system_admin','cement_accountant'], true);
        foreach ($context->auth['recordTypes'] as $native) {
            $code = M::code($native);
            if (!isset($read[M::id($native)]) || (!$privateAllowed && (str_starts_with($code, 'payroll') || in_array($code, ['staff_employment','staff_deduction','person','cement_audit_event'], true)))) {
                continue;
            }
            $aliases = M::properties($native)['settings']['cementFieldAliases'] ?? null;
            if (isset($known[$code]) && is_array($aliases)) {
                $maps[$code] = $aliases;
                $ids[$code] = M::id($native);
            }
        }
        ksort($maps);
        ksort($ids);
        $revision = 'native:'.hash('sha256', json_encode([(new \App\Libraries\MetaForge\Catalog\MetadataRevision(\Config\Database::connect()))->current(),$maps,$ids], JSON_THROW_ON_ERROR));
        if (($envelope['registryRevision'] ?? null) !== $revision) {
            throw new \App\Libraries\MetaForge\Mutation\ValidationException([['fieldPath' => 'registryRevision','code' => 'conflict','message' => 'Cement field registry changed; reload before retrying']]);
        }
        $entries = $envelope['batch'] ?? [$envelope];
        // Until dependent plans have explicit identity bindings, refuse rather than split atomic requests.
        if (!is_array($entries) || count($entries) !== 1) {
            throw new \InvalidArgumentException('Native connected-batch runtime is not installed');
        }
        $entry = $entries[0];
        $type = $entry['recordTypeCode'] ?? '';
        if (!in_array($type, self::MASTERS, true)) {
            throw new \RuntimeException('Native Cement posting runtime is not installed');
        }
        $meta = $context->catalog->metadata($type);
        $id = M::id($meta);
        $op = $entry['operation'] ?? null;
        if (!in_array($op, ['insert','update','delete'], true) || ($entry['recordTypeId'] ?? null) !== $id) {
            throw new \InvalidArgumentException('Invalid native mutation identity');
        }
        $context->type($id, $op);
        $recordId = $entry['recordId'] ?? 0;
        if ($op !== 'insert') {
            $repo = new RecordRepository(\Config\Database::connect());
            $old = $repo->find($repo->table($context, $meta), $id, $recordId, true);
            if (!$old || M::physical($old, 'isDeleted', 0)) {
                throw new \RuntimeException('Record is no longer available');
            }
            if (($entry['version'] ?? null) !== max(1, M::physical($old, 'mutationId', 0))) {
                throw new \App\Libraries\MetaForge\Mutation\ValidationException([['fieldPath' => 'version','code' => 'conflict','message' => 'Record changed; reload before saving']]);
            }
        } elseif ($recordId) {
            throw new \InvalidArgumentException('Insert cannot choose a record ID');
        }
        if ($op === 'delete') {
            $repo = new RecordRepository(\Config\Database::connect());
            $specs = json_decode(file_get_contents(ROOTPATH.'resources/cement/recordtypes.json'), true, 64, JSON_THROW_ON_ERROR);
            foreach ($specs as $spec) {
                foreach ($spec['fields'] as $field) {
                    if ($field['target'] === $type) {
                        $other = $context->catalog->metadata($spec['code']);
                        // Referential integrity is checked in the institution's native table, independent of UI visibility.
                        foreach ($repo->query($repo->table($context, $other), M::id($other)) as $r) {
                            if (M::property($r, $field['code']) === $recordId) {
                                throw new \RuntimeException('Referenced native records cannot be deleted');
                            }
                        }
                    }
                }
            }
        }
        if (isset($entry['clientRef'])) {
            throw new \InvalidArgumentException('Connected native mutation bindings are unavailable');
        }
        $plan = ['recordTypeId' => $id,'operation' => $op,'data' => $entry['data'] ?? []];
        if ($op !== 'insert') {
            $plan['recordId'] = $recordId;
        }
        return [K::SERVICE_MUTATION_PLANS => [$plan],'results' => ['resultMap' => []]];
    }
}
