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
        PurchaseDrafts::register($registry);
        NativeLedger::register($registry);
        NativeInventory::register($registry);
        NativeLoading::register($registry);
        NativeSales::register($registry);
        NativeStockControls::register($registry);
        NativeReturns::register($registry);
        NativeReservations::register($registry);
        NativeFleet::register($registry);
        NativeSupplierPayments::register($registry);
        NativeIncentives::register($registry);
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
        if (!TrustedPlans::inside()) {
            throw new \RuntimeException('Cement business writes require the authorised cement.execute entry service');
        }
        if (!in_array($recordTypeCode, [...self::MASTERS,'purchase','purchaseItem',...NativeInventory::TYPES,...NativeLoading::TYPES,...NativeSales::TYPES,...NativeStockControls::TYPES,...NativeReturns::TYPES,...NativeReservations::TYPES,...NativeFleet::TYPES,...NativeSupplierPayments::TYPES,...NativeIncentives::TYPES], true)) {
            return [K::SERVICE_VALIDATION_ERRORS => [['fieldPath' => 'status','code' => 'cement_posting_unavailable','message' => 'This Cement transaction requires its verified native business runtime; no stock or ledger effect was posted.']]];
        }
        if (in_array($recordTypeCode, NativeInventory::TYPES, true) && !isset(M::properties($context->catalog->metadata($recordTypeCode))['settings']['cementInventory'])) {
            throw new \RuntimeException('Native inventory handlers are not activated');
        }
        if (in_array($recordTypeCode, NativeLoading::TYPES, true) && !isset(M::properties($context->catalog->metadata($recordTypeCode))['settings']['cementLoading'])) {
            throw new \RuntimeException('Native loading handlers are not activated');
        }
        if (in_array($recordTypeCode, NativeSales::TYPES, true) && !isset(M::properties($context->catalog->metadata($recordTypeCode))['settings']['cementSales'])) {
            throw new \RuntimeException('Native sales handlers are not activated');
        }
        if (in_array($recordTypeCode, NativeStockControls::TYPES, true) && !isset(M::properties($context->catalog->metadata($recordTypeCode))['settings']['cementStockControls'])) {
            throw new \RuntimeException('Native stock controls are not activated');
        }
        if (in_array($recordTypeCode, NativeReturns::TYPES, true) && !isset(M::properties($context->catalog->metadata($recordTypeCode))['settings']['cementReturns'])) {
            throw new \RuntimeException('Native returns are not activated');
        }
        if ($recordTypeCode === 'inventory_reservation' && !isset(M::properties($context->catalog->metadata($recordTypeCode))['settings']['cementReservations'])) {
            throw new \RuntimeException('Native reservations are not activated');
        }
        if (in_array($recordTypeCode, NativeFleet::TYPES, true) && !isset(M::properties($context->catalog->metadata($recordTypeCode))['settings']['cementFleet'])) {
            throw new \RuntimeException('Native fleet services are not activated');
        }
        if (in_array($recordTypeCode, NativeSupplierPayments::TYPES, true) && !isset(M::properties($context->catalog->metadata($recordTypeCode))['settings']['cementSupplierPayments'])) {
            throw new \RuntimeException('Native supplier payments are not activated');
        }
        if (in_array($recordTypeCode, NativeIncentives::TYPES, true) && !isset(M::properties($context->catalog->metadata($recordTypeCode))['settings']['cementIncentives'])) {
            throw new \RuntimeException('Native incentives are not activated');
        }
        $private = $record['settings']['cement'] ?? [];
        $oldPrivate = $previous['settings']['cement'] ?? [];
        if ($private !== $oldPrivate) {
            $computedLoading = $recordTypeCode === 'cement_loading' && ($record[$context->catalog->metadata('cement_loading')['jsonData']['settings']['cementFieldAliases']['STATUS']] ?? null) === 'confirmed' && isset($context->catalog->metadata('cement_loading')['jsonData']['settings']['cementLoading']);
            $nativeSettings = M::properties($context->catalog->metadata($recordTypeCode))['settings'];
            $f = $nativeSettings['cementFieldAliases'];
            $computedSales = isset($nativeSettings['cementSales']) && (($recordTypeCode === 'customer_sales' && ($record[$f['STATUS']] ?? null) === 'invoiced') || ($recordTypeCode === 'customer_payment' && ($record[$f['STATUS']] ?? null) === 'completed'));
            $computedControls = isset($nativeSettings['cementStockControls']) && ($recordTypeCode === 'inventory_transfer' || $recordTypeCode === 'inventory_stocktake_line' || ($recordTypeCode === 'damaged_goods' && ($record[$f['STATUS']] ?? null) === 'confirmed'));
            $computedReturns = isset($nativeSettings['cementReturns']) && ($record[$f['STATUS']] ?? null) === 'confirmed';
            $computedPurchase = $recordTypeCode === 'purchase' && NativeSupplierPayments::enabled(new NativeRecords($context));
            $computedIncentive = $recordTypeCode === 'supplier_incentive' && isset($nativeSettings['cementIncentives']);
            if (!$computedIncentive && !$computedPurchase && !$computedLoading && !$computedSales && !$computedControls && !$computedReturns && !in_array(TrustedPlans::origin(), ['cement.loading','cement.sales','cement.sales.balance','cement.advance','cement.supplier_payment','cement.payment','cement.delivery','cement.stocktake','cement.transfer','cement.damage','cement.return'], true)) {
                throw new \RuntimeException('Cement processing snapshots are server-owned');
            }
        }
        $catalog = $context->catalog;
        $meta = $catalog->metadata($recordTypeCode);
        $definition = M::properties($meta);
        $aliases = $definition['settings']['cementFieldAliases'];
        if (in_array($recordTypeCode, ['purchase','purchaseItem'], true)) {
            $state = $record[$aliases['STATUS']] ?? 'draft';
            $oldState = $previous[$aliases['STATUS']] ?? 'draft';
            $permitted = $state === 'draft' || ($recordTypeCode === 'purchase' && $state === 'paid' && isset($definition['settings']['cementPurchaseFunding']));
            if (!isset($definition['settings']['cementPurchaseDrafts']) || !$permitted || ($previous && $oldState !== 'draft')) {
                return [K::SERVICE_VALIDATION_ERRORS => [['fieldPath' => $aliases['STATUS'],'code' => 'cement_posting_unavailable','message' => 'This purchase transition requires its verified native handler']]];
            }
        }
        $source = NativeSchemas::types();
        $source = array_column($source, null, 'code');
        $repo = new RecordRepository(\Config\Database::connect());
        foreach ($source[$recordTypeCode]['fields'] as $field) {
            if ($field['target'] !== null) {
                $alias = array_search($field['code'], NativeSchemas::maps()[$recordTypeCode], true);
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
        NativeMasterRules::validate($recordTypeCode, $record, $previous, $context);
        NativeFiles::validate($context, $recordTypeCode, (new NativeRecords($context))->decode($recordTypeCode, $record));
        return [];
    }
    public function execute(array $envelope, MutationContext $context): array
    {
        if (!is_string($envelope['requestId'] ?? null)) {
            throw new \InvalidArgumentException('Original request identity required');
        }
        $maps = [];
        $ids = [];
        $known = NativeSchemas::maps();
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
        if (!is_array($entries) || !array_is_list($entries) || !$entries || count($entries) > 100) {
            throw new \InvalidArgumentException('Batch must contain 1–100 entries');
        }
        $plans = [];
        $seen = [];
        $bindings = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('Invalid batch entry');
            }
            $identity = ($entry['recordTypeCode'] ?? '').':'.($entry['recordId'] ?? '');
            if (($entry['operation'] ?? 'insert') !== 'insert' && isset($seen[$identity])) {
                throw new \InvalidArgumentException('A record may only be changed once in a batch');
            }
            $seen[$identity] = true;
            $plan = $this->plan($entry, $context);
            $ref = $entry['clientRef'] ?? null;
            if ($ref !== null) {
                if (!is_string($ref) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $ref) || isset($bindings[$ref])) {
                    throw new \InvalidArgumentException('Invalid or duplicate batch identity');
                }$plan['_cementClientRef'] = $ref;
            }
            $type = $entry['recordTypeCode'];
            $source = array_column(NativeSchemas::types(), null, 'code');
            $initial = NativeSchemas::maps();
            $current = M::properties($context->catalog->metadata($type))['settings']['cementFieldAliases'];
            $targets = [];
            foreach ($source[$type]['fields'] as $field) {
                if ($field['target'] !== null) {
                    $alias = array_search($field['code'], $initial[$type], true);
                    $targets[$current[$alias]] = $field['target'];
                }
            }
            foreach ($plan['data'] as $field => $value) {
                if (is_array($value) && array_keys($value) === ['$ref']) {
                    $target = $value['$ref'];
                    if (!is_string($target) || !isset($bindings[$target]) || !isset($targets[$field]) || $bindings[$target] !== $targets[$field]) {
                        throw new \InvalidArgumentException('Batch reference must match an earlier declared relationship');
                    }
                    $plan['_cementReferences'][$field] = $target;
                    unset($plan['data'][$field]);
                }
            }
            if ($ref !== null) {
                $bindings[$ref] = $type;
            }
            $plan['_cementEntry'] = true;
            $plans[] = $plan;
        }
        return [K::SERVICE_MUTATION_PLANS => $plans,'results' => ['resultMap' => []]];
    }
    private function plan(array $entry, MutationContext $context): array
    {
        $type = $entry['recordTypeCode'] ?? '';
        if (!in_array($type, [...self::MASTERS,'purchase','purchaseItem',...NativeInventory::TYPES,...NativeLoading::TYPES,...NativeSales::TYPES,...NativeStockControls::TYPES,...NativeReturns::TYPES,...NativeReservations::TYPES,...NativeFleet::TYPES,...NativeSupplierPayments::TYPES,...NativeIncentives::TYPES], true)) {
            throw new \RuntimeException('Native Cement posting runtime is not installed');
        }
        $meta = $context->catalog->metadata($type);
        if (in_array($type, ['purchase','purchaseItem'], true) && !isset(M::properties($meta)['settings']['cementPurchaseDrafts'])) {
            throw new \RuntimeException('Native purchase draft handlers are not activated');
        }
        if (in_array($type, ['inventory_stock','inventory_cost_layer'], true)) {
            throw new \RuntimeException('Stock projections are service-owned');
        }
        if ($type === 'inventory_movement' && !isset(M::properties($meta)['settings']['cementInventory'])) {
            throw new \RuntimeException('Native inventory handlers are not activated');
        }
        if (in_array($type, NativeLoading::TYPES, true) && !isset(M::properties($meta)['settings']['cementLoading'])) {
            throw new \RuntimeException('Native loading handlers are not activated');
        }
        if (in_array($type, NativeSales::TYPES, true) && !isset(M::properties($meta)['settings']['cementSales'])) {
            throw new \RuntimeException('Native sales handlers are not activated');
        }
        if (in_array($type, NativeStockControls::TYPES, true) && !isset(M::properties($meta)['settings']['cementStockControls'])) {
            throw new \RuntimeException('Native stock controls are not activated');
        }
        if (in_array($type, NativeReturns::TYPES, true) && !isset(M::properties($meta)['settings']['cementReturns'])) {
            throw new \RuntimeException('Native returns are not activated');
        }
        if ($type === 'inventory_reservation' && !isset(M::properties($meta)['settings']['cementReservations'])) {
            throw new \RuntimeException('Native reservations are not activated');
        }
        if (in_array($type, NativeFleet::TYPES, true) && !isset(M::properties($meta)['settings']['cementFleet'])) {
            throw new \RuntimeException('Native fleet services are not activated');
        }
        if (in_array($type, NativeSupplierPayments::TYPES, true) && !isset(M::properties($meta)['settings']['cementSupplierPayments'])) {
            throw new \RuntimeException('Native supplier payments are not activated');
        }
        if (in_array($type, NativeIncentives::TYPES, true) && !isset(M::properties($meta)['settings']['cementIncentives'])) {
            throw new \RuntimeException('Native incentives are not activated');
        }
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
            $specs = NativeSchemas::types();
            foreach ($specs as $spec) {
                if (!isset($context->catalog->all()['recordTypes']['meta'][$spec['code']])) {
                    continue;
                }
                foreach ($spec['fields'] as $field) {
                    if ($field['target'] === $type) {
                        $other = $context->catalog->metadata($spec['code']);
                        // Referential integrity is checked in the institution's native table, independent of UI visibility.
                        foreach ($repo->query($repo->table($context, $other), M::id($other)) as $r) {
                            $initial = NativeSchemas::maps();
                            $alias = array_search($field['code'], $initial[$spec['code']], true);
                            $actual = M::properties($other)['settings']['cementFieldAliases'][$alias] ?? null;
                            if (!$actual) {
                                throw new \RuntimeException('Reference schema is not mapped');
                            }
                            if (M::property($r, $actual) === $recordId) {
                                throw new \RuntimeException('Referenced native records cannot be deleted');
                            }
                        }
                    }
                }
            }
        }
        if (!is_array($entry['data'] ?? []) || (($entry['data'] ?? []) !== [] && array_is_list($entry['data']))) {
            throw new \InvalidArgumentException('Mutation data must be an object');
        }
        $plan = ['recordTypeId' => $id,'operation' => $op,'data' => $entry['data'] ?? []];
        foreach (M::properties($meta)['fields'] as $field) {
            if (in_array($field['type'], ['number','decimal'], true) && isset($plan['data'][$field['code']]) && is_float($plan['data'][$field['code']])) {
                if (!is_finite($plan['data'][$field['code']])) {
                    throw new \InvalidArgumentException('Number must be finite');
                }$plan['data'][$field['code']] = \App\Libraries\MetaForge\Catalog\FieldType::numericString($plan['data'][$field['code']]);
            }
        }
        if ($op !== 'insert') {
            $plan['recordId'] = $recordId;
        }
        foreach (M::properties($meta)['fields'] as $field) {
            if ($field['type'] === 'datetime' && isset($plan['data'][$field['code']]) && is_string($plan['data'][$field['code']])) {
                $plan['data'][$field['code']] = NativeDates::canonical($plan['data'][$field['code']]);
            }
        }
        return $plan;
    }
}
