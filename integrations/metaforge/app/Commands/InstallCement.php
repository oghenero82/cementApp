<?php

namespace App\Commands;

use App\Libraries\MetaForge\Catalog\{CatalogReader,CatalogStore,Metadata as M,MetadataRevision};
use App\Libraries\MetaForge\System\keys\{SystemKeys as S,DefinitionKeys as D,AuthKeys as A,DatabaseKeys as B,SeedKeys,MutationKeys};
use CodeIgniter\CLI\{BaseCommand,CLI};
use Config\Database;

/** Explicit CLI installation; never runs from a request or alters an existing schema. */
final class InstallCement extends BaseCommand
{
    protected $group = 'Cement';
    protected $name = 'cement:install-native';
    protected $description = 'Install Cement definitions and guarded catalog services into the native MetaForge catalog.';
    protected $options = ['--native-supplier-payments' => 'Enable partial supplier payments and remaining funding.','--native-fleet' => 'Enable ordered delivery trips and operating-cost posting.','--native-reservations' => 'Enable explicit warehouse order reservations.','--native-returns' => 'Enable credits, refunds and original-cost return receipts.','--native-stock-controls' => 'Enable approved counts, adjustments, transfers and damage controls.','--native-sales' => 'Enable customer orders, recognition, receipts and delivery proof.','--native-loading' => 'Enable loading and partial supplier clearance.','--native-inventory' => 'Enable verified movements and stock/cost projections.','--purchase-funding' => 'Enable verified shared Accounting and purchase funding.','--purchase-drafts' => 'Install checked native purchase draft handlers.','--upgrade' => 'Apply the checked capability metadata migration.', '--grant-role' => 'Explicitly grant the installed types to this existing role code (optional).'];
    public function run(array $params)
    {
        $db = Database::connect();
        $locked = false;
        $begun = false;
        try {
            $locked = (int)$db->query('SELECT GET_LOCK(?,20) AS acquired', [MutationKeys::COMMIT_LOCK_NAME])->getRow()->acquired === 1;
            if (!$locked) {
                throw new \RuntimeException('MetaForge mutation lock is busy');
            }
            $store = new CatalogStore($db);
            $catalog = CatalogReader::fromStore($store);
            $root = M::id($catalog->definition(S::BASE_RECORD_TYPES));
            $physical = M::id($catalog->record(S::CODE_PHYSICAL_TABLES, SeedKeys::RECORD_TABLE));
            $reservationRow = $db->table(B::TABLE_SYSTEM)->where('recordCode', 'inventory_reservation')->get()->getRowArray();
            $reservationsEnabled = CLI::getOption('native-reservations') || ($reservationRow && (json_decode($reservationRow['jsonData'], true, 64, JSON_THROW_ON_ERROR)['settings']['cementReservations'] ?? false));
            $types = \App\Libraries\CementIntegration\NativeSchemas::types((bool)$reservationsEnabled);
            $aliases = \App\Libraries\CementIntegration\NativeSchemas::maps();
            // Validate the trusted handler exists before making catalog changes.
            (new \Config\MetaForge())->services()->descriptorCode(['code' => 'cement.catalog.guard']);
            $beforeCount = $db->table(B::TABLE_SYSTEM)->countAllResults();
            $changed = false;
            $db->transBegin();
            $begun = true;
            $ids = [];
            $purchaseRow = $db->table(B::TABLE_SYSTEM)->where('recordCode', 'purchase')->get()->getRowArray();
            $movementRow = $db->table(B::TABLE_SYSTEM)->where('recordCode', 'inventory_movement')->get()->getRowArray();
            $loadingRow = $db->table(B::TABLE_SYSTEM)->where('recordCode', 'cement_loading')->get()->getRowArray();
            $salesRow = $db->table(B::TABLE_SYSTEM)->where('recordCode', 'customer_sales')->get()->getRowArray();
            $returnsRow = $db->table(B::TABLE_SYSTEM)->where('recordCode', 'customer_sale_return')->get()->getRowArray();
            $returnsEnabled = CLI::getOption('native-returns') || ($returnsRow && (json_decode($returnsRow['jsonData'], true, 64, JSON_THROW_ON_ERROR)['settings']['cementReturns'] ?? false));
            $fleetRow = $db->table(B::TABLE_SYSTEM)->where('recordCode', 'trip')->get()->getRowArray();
            $fleetEnabled = CLI::getOption('native-fleet') || ($fleetRow && (json_decode($fleetRow['jsonData'], true, 64, JSON_THROW_ON_ERROR)['settings']['cementFleet'] ?? false));
            $salesEnabled = $fleetEnabled || $reservationsEnabled || $returnsEnabled || CLI::getOption('native-sales') || ($salesRow && (json_decode($salesRow['jsonData'], true, 64, JSON_THROW_ON_ERROR)['settings']['cementSales'] ?? false));
            $loadingEnabled = $salesEnabled || CLI::getOption('native-loading') || ($loadingRow && (json_decode($loadingRow['jsonData'], true, 64, JSON_THROW_ON_ERROR)['settings']['cementLoading'] ?? false));
            $stockControlsRow = $db->table(B::TABLE_SYSTEM)->where('recordCode', 'inventory_stocktake')->get()->getRowArray();
            $stockControlsEnabled = CLI::getOption('native-stock-controls') || ($stockControlsRow && (json_decode($stockControlsRow['jsonData'], true, 64, JSON_THROW_ON_ERROR)['settings']['cementStockControls'] ?? false));
            $inventoryEnabled = $stockControlsEnabled || CLI::getOption('native-inventory') || $loadingEnabled || ($movementRow && (json_decode($movementRow['jsonData'], true, 64, JSON_THROW_ON_ERROR)['settings']['cementInventory'] ?? false));
            $supplierPaymentRow = $db->table(B::TABLE_SYSTEM)->where('recordCode', 'supplier_purchase_payment')->get()->getRowArray();
            $supplierPaymentsEnabled = CLI::getOption('native-supplier-payments') || ($supplierPaymentRow && (json_decode($supplierPaymentRow['jsonData'], true, 64, JSON_THROW_ON_ERROR)['settings']['cementSupplierPayments'] ?? false));
            $fundingEnabled = $supplierPaymentsEnabled || CLI::getOption('purchase-funding') || $inventoryEnabled || ($purchaseRow && (json_decode($purchaseRow['jsonData'], true, 64, JSON_THROW_ON_ERROR)['settings']['cementPurchaseFunding'] ?? false));
            if ($fundingEnabled && !extension_loaded('bcmath')) {
                throw new \RuntimeException('Native accounting posting requires the PHP BCMath extension');
            }
            if ($fundingEnabled) {
                $changed = \App\Libraries\CementIntegration\LedgerInstallation::install($db, $store, $catalog, $physical) || $changed;
            }
            foreach ($types as $type) {
                $fields = [];
                foreach ($type['fields'] as $field) {
                    $fields[] = ['code' => $field['code'],'type' => $field['type'] === 'reference' ? 'integer' : $field['type'],'properties' => ['required' => $field['required'],'persistent' => true]];
                }
                $definition = ['code' => $type['code'],'name' => $type['name'],'status' => 1,'ptableId' => $physical,'isSync' => true,'actions' => ['read','insert','update','delete'],'fields' => $fields,
                    'settings' => ['cementFieldAliases' => $aliases[$type['code']],'cementSchemaVersion' => 1,'cementBusinessRuntime' => 'unavailable','cementOperations' => in_array($type['code'], \App\Libraries\CementIntegration\CatalogService::MASTERS, true) ? ['read','insert','update','delete'] : ['read']],
                    'services' => [['code' => 'cement_guard','service' => 'cement.catalog.guard','method' => 'validate','stage' => 'beforeSave','trigger' => 'true','order' => -1000,'parameters' => ['recordTypeCode' => $type['code'],'record' => ['source' => 'data'],'previous' => ['source' => 'old']]]],
                    'searchColumnsMapping' => [],'uniques' => []];
                // The original seed defines uniqueness by business fields, not guessed columns.
                $columns = [];
                foreach (array_slice(array_values(array_unique(array_merge(array_merge(...$type['uniques']), $type['indexes']))), 0, 10) as $n => $field) {
                    $columns[$field] = 'search'.($n + 1);
                }
                $definition['searchColumnsMapping'] = $columns;
                $definition['uniques'] = array_map(fn ($keys, $n) => ['code' => 'unique_'.$n,'fields' => $keys], $type['uniques'], array_keys($type['uniques']));
                if (CLI::getOption('upgrade')) {
                    $existing = $db->table(B::TABLE_SYSTEM)->where('recordCode', $type['code'])->get()->getRowArray();
                    if ($existing) {
                        $stored = json_decode($existing['jsonData'], true, 64, JSON_THROW_ON_ERROR);
                        if (!isset($stored['settings']['cementOperations'])) {
                            $old = $definition;
                            unset($old['settings']['cementOperations']);
                            $store->ensure($type['code'], $root, $old);
                            $changed = true;
                            $stored['settings']['cementOperations'] = $definition['settings']['cementOperations'];
                            $db->table(B::TABLE_SYSTEM)->where('recordId', $existing['recordId'])->update(['jsonData' => json_encode((object)$stored, JSON_THROW_ON_ERROR)]);
                        }
                    }
                }
                $current = $db->table(B::TABLE_SYSTEM)->where('recordCode', $type['code'])->get()->getRowArray();
                $draftsEnabled = $current ? (json_decode($current['jsonData'], true, 64, JSON_THROW_ON_ERROR)['settings']['cementPurchaseDrafts'] ?? false) : false;
                if ((CLI::getOption('purchase-drafts') || $draftsEnabled || $fundingEnabled) && in_array($type['code'], ['purchase','purchaseItem'], true)) {
                    $oldDefinition = $definition;
                    $definition = \App\Libraries\CementIntegration\PurchaseDrafts::definition($definition);
                    $existing = $db->table(B::TABLE_SYSTEM)->where('recordCode', $type['code'])->get()->getRowArray();
                    if ($existing) {
                        $stored = json_decode($existing['jsonData'], true, 64, JSON_THROW_ON_ERROR);
                        if (!isset($stored['settings']['cementPurchaseDrafts'])) {
                            $store->ensure($type['code'], $root, $oldDefinition);
                            $new = \App\Libraries\MetaForge\Catalog\CatalogContract::normalize(S::BASE_RECORD_TYPES, $definition, $catalog);
                            $db->table(B::TABLE_SYSTEM)->where('recordId', $existing['recordId'])->update(['jsonData' => json_encode((object)$new, JSON_THROW_ON_ERROR)]);
                            $changed = true;
                        }
                    }
                }
                if ($fundingEnabled && $type['code'] === 'purchase') {
                    $oldDefinition = $definition;
                    $definition = \App\Libraries\CementIntegration\LedgerInstallation::purchase($definition);
                    $existing = $db->table(B::TABLE_SYSTEM)->where('recordCode', 'purchase')->get()->getRowArray();
                    if ($existing) {
                        $stored = json_decode($existing['jsonData'], true, 64, JSON_THROW_ON_ERROR);
                        if (!isset($stored['settings']['cementPurchaseFunding'])) {
                            $store->ensure('purchase', $root, $oldDefinition);
                            $new = \App\Libraries\MetaForge\Catalog\CatalogContract::normalize(S::BASE_RECORD_TYPES, $definition, $catalog);
                            $db->table(B::TABLE_SYSTEM)->where('recordId', $existing['recordId'])->update(['jsonData' => json_encode((object)$new, JSON_THROW_ON_ERROR)]);
                            $changed = true;
                        }
                    }
                }
                if ($inventoryEnabled && in_array($type['code'], \App\Libraries\CementIntegration\NativeInventory::TYPES, true)) {
                    $oldDefinition = $definition;
                    $definition = \App\Libraries\CementIntegration\NativeInventory::definition($definition);
                    $existing = $db->table(B::TABLE_SYSTEM)->where('recordCode', $type['code'])->get()->getRowArray();
                    if ($existing) {
                        $stored = json_decode($existing['jsonData'], true, 64, JSON_THROW_ON_ERROR);
                        if (!isset($stored['settings']['cementInventory'])) {
                            $store->ensure($type['code'], $root, $oldDefinition);
                            $new = \App\Libraries\MetaForge\Catalog\CatalogContract::normalize(S::BASE_RECORD_TYPES, $definition, $catalog);
                            $db->table(B::TABLE_SYSTEM)->where('recordId', $existing['recordId'])->update(['jsonData' => json_encode((object)$new, JSON_THROW_ON_ERROR)]);
                            $changed = true;
                        }
                    }
                }
                if ($loadingEnabled && in_array($type['code'], \App\Libraries\CementIntegration\NativeLoading::TYPES, true)) {
                    $oldDefinition = $definition;
                    $definition = \App\Libraries\CementIntegration\NativeLoading::definition($definition);
                    $existing = $db->table(B::TABLE_SYSTEM)->where('recordCode', $type['code'])->get()->getRowArray();
                    if ($existing) {
                        $stored = json_decode($existing['jsonData'], true, 64, JSON_THROW_ON_ERROR);
                        if (!isset($stored['settings']['cementLoading'])) {
                            $store->ensure($type['code'], $root, $oldDefinition);
                            $new = \App\Libraries\MetaForge\Catalog\CatalogContract::normalize(S::BASE_RECORD_TYPES, $definition, $catalog);
                            $db->table(B::TABLE_SYSTEM)->where('recordId', $existing['recordId'])->update(['jsonData' => json_encode((object)$new, JSON_THROW_ON_ERROR)]);
                            $changed = true;
                        }
                    }
                }
                if ($salesEnabled && in_array($type['code'], \App\Libraries\CementIntegration\NativeSales::TYPES, true)) {
                    $oldDefinition = $definition;
                    $definition = \App\Libraries\CementIntegration\NativeSales::definition($definition);
                    $existing = $db->table(B::TABLE_SYSTEM)->where('recordCode', $type['code'])->get()->getRowArray();
                    if ($existing) {
                        $stored = json_decode($existing['jsonData'], true, 64, JSON_THROW_ON_ERROR);
                        if (!isset($stored['settings']['cementSales'])) {
                            $store->ensure($type['code'], $root, $oldDefinition);
                            $new = \App\Libraries\MetaForge\Catalog\CatalogContract::normalize(S::BASE_RECORD_TYPES, $definition, $catalog);
                            $db->table(B::TABLE_SYSTEM)->where('recordId', $existing['recordId'])->update(['jsonData' => json_encode((object)$new, JSON_THROW_ON_ERROR)]);
                            $changed = true;
                        }
                    }
                }
                if ($stockControlsEnabled && in_array($type['code'], \App\Libraries\CementIntegration\NativeStockControls::TYPES, true)) {
                    $oldDefinition = $definition;
                    $definition = \App\Libraries\CementIntegration\NativeStockControls::definition($definition);
                    $existing = $db->table(B::TABLE_SYSTEM)->where('recordCode', $type['code'])->get()->getRowArray();
                    if ($existing) {
                        $stored = json_decode($existing['jsonData'], true, 64, JSON_THROW_ON_ERROR);
                        if (!isset($stored['settings']['cementStockControls'])) {
                            $store->ensure($type['code'], $root, $oldDefinition);
                            $new = \App\Libraries\MetaForge\Catalog\CatalogContract::normalize(S::BASE_RECORD_TYPES, $definition, $catalog);
                            $db->table(B::TABLE_SYSTEM)->where('recordId', $existing['recordId'])->update(['jsonData' => json_encode((object)$new, JSON_THROW_ON_ERROR)]);
                            $changed = true;
                        }
                    }
                }
                if ($returnsEnabled && $type['code'] === 'customer_sale_return') {
                    $oldDefinition = $definition;
                    $definition = \App\Libraries\CementIntegration\NativeReturns::definition($definition);
                    $existing = $db->table(B::TABLE_SYSTEM)->where('recordCode', $type['code'])->get()->getRowArray();
                    if ($existing) {
                        $stored = json_decode($existing['jsonData'], true, 64, JSON_THROW_ON_ERROR);
                        if (!isset($stored['settings']['cementReturns'])) {
                            $store->ensure($type['code'], $root, $oldDefinition);
                            $new = \App\Libraries\MetaForge\Catalog\CatalogContract::normalize(S::BASE_RECORD_TYPES, $definition, $catalog);
                            $db->table(B::TABLE_SYSTEM)->where('recordId', $existing['recordId'])->update(['jsonData' => json_encode((object)$new, JSON_THROW_ON_ERROR)]);
                            $changed = true;
                        }
                    }
                }
                if ($fleetEnabled && in_array($type['code'], \App\Libraries\CementIntegration\NativeFleet::TYPES, true)) {
                    $oldDefinition = $definition;
                    $definition = \App\Libraries\CementIntegration\NativeFleet::definition($definition);
                    $existing = $db->table(B::TABLE_SYSTEM)->where('recordCode', $type['code'])->get()->getRowArray();
                    if ($existing) {
                        $stored = json_decode($existing['jsonData'], true, 64, JSON_THROW_ON_ERROR);
                        if (!isset($stored['settings']['cementFleet'])) {
                            $store->ensure($type['code'], $root, $oldDefinition);
                            $new = \App\Libraries\MetaForge\Catalog\CatalogContract::normalize(S::BASE_RECORD_TYPES, $definition, $catalog);
                            $db->table(B::TABLE_SYSTEM)->where('recordId', $existing['recordId'])->update(['jsonData' => json_encode((object)$new, JSON_THROW_ON_ERROR)]);
                            $changed = true;
                        }
                    }
                }
                if ($supplierPaymentsEnabled && $type['code'] === 'supplier_purchase_payment') {
                    $oldDefinition = $definition;
                    $definition = \App\Libraries\CementIntegration\NativeSupplierPayments::definition($definition);
                    $existing = $db->table(B::TABLE_SYSTEM)->where('recordCode', $type['code'])->get()->getRowArray();
                    if ($existing) {
                        $stored = json_decode($existing['jsonData'], true, 64, JSON_THROW_ON_ERROR);
                        if (!isset($stored['settings']['cementSupplierPayments'])) {
                            $store->ensure($type['code'], $root, $oldDefinition);
                            $new = \App\Libraries\MetaForge\Catalog\CatalogContract::normalize(S::BASE_RECORD_TYPES, $definition, $catalog);
                            $db->table(B::TABLE_SYSTEM)->where('recordId', $existing['recordId'])->update(['jsonData' => json_encode((object)$new, JSON_THROW_ON_ERROR)]);
                            $changed = true;
                        }
                    }
                }
                if ($type['code'] === 'inventory_reservation') {
                    $definition = \App\Libraries\CementIntegration\NativeReservations::definition($definition);
                }
                $ids[$type['code']] = $store->ensure($type['code'], $root, $definition);
            }
            $moduleRow = $db->table(B::TABLE_SYSTEM)->where('recordCode', 'cement_native')->get()->getRowArray();
            if ($reservationsEnabled && $moduleRow) {
                $moduleData = json_decode($moduleRow['jsonData'], true, 64, JSON_THROW_ON_ERROR);
                if (!in_array($ids['inventory_reservation'], $moduleData['recordTypeIds'], true)) {
                    $oldIds = $ids;
                    unset($oldIds['inventory_reservation']);
                    $store->ensure('cement_native', M::id($catalog->metadata(S::CODE_MODULES)), ['code' => 'cement_native','name' => 'Cement','recordTypeIds' => array_values($oldIds),'status' => 1]);
                    $moduleData['recordTypeIds'] = array_values($ids);
                    $db->table(B::TABLE_SYSTEM)->where('recordId', $moduleRow['recordId'])->update(['jsonData' => json_encode((object)$moduleData, JSON_THROW_ON_ERROR)]);
                    $changed = true;
                }
            }
            $module = $store->ensure('cement_native', M::id($catalog->metadata(S::CODE_MODULES)), ['code' => 'cement_native','name' => 'Cement','recordTypeIds' => array_values($ids),'status' => 1]);
            $roleCode = CLI::getOption('grant-role');
            if ($roleCode) {
                $roleRows = $db->table(B::TABLE_USERS)->where('recordCode', $roleCode)->where('recordTypeId', M::id($catalog->metadata(S::CODE_ROLE)))->get()->getResultArray();
                if (count($roleRows) !== 1) {
                    throw new \RuntimeException('Grant role code must identify exactly one existing role');
                }
                $role = M::normalize($roleRows[0]);
                $data = M::properties($role);
                $permissions = $data['permissions'] ?? [];
                $grantIds = $ids;
                if ($fundingEnabled) {
                    foreach (['account','bank_account','fiscal_year','journal','journal_line','account_balance'] as $type) {
                        $grantIds[$type] = M::id($catalog->metadata($type));
                    }
                }
                foreach ($grantIds as $type => $id) {
                    $actions = isset($ids[$type]) ? ['read','insert','update','delete'] : (in_array($type, ['account','bank_account','fiscal_year'], true) ? ['read'] : ['read','insert','update']);
                    foreach ($actions as $action) {
                        $grant = ['rtid' => $id,'action' => $action,'allowed' => true];
                        if (!in_array($grant, $permissions, true)) {
                            $permissions[] = $grant;
                        }
                    }
                }
                $changed = $changed || $data['permissions'] !== $permissions;
                $data['permissions'] = $permissions;
                $db->table(B::TABLE_USERS)->where('recordId', M::id($role))->update(['jsonData' => json_encode((object)$data, JSON_THROW_ON_ERROR)]);
                $portal = $catalog->record(S::BASE_PORTALS, $data[A::ROLE_PORTAL_ID]);
                $pd = M::properties($portal);
                $pd['moduleIds'] = array_values(array_unique([...($pd['moduleIds'] ?? []),$module]));
                $db->table(B::TABLE_SYSTEM)->where('recordId', M::id($portal))->update(['jsonData' => json_encode((object)$pd, JSON_THROW_ON_ERROR)]);
                $institution = $catalog->record(S::CODE_INSTITUTIONS, $data[A::USER_INSTITUTION_ID]);
                $ins = M::properties($institution);
                $it = $catalog->record(S::CODE_INSTITUTION_TYPES, $ins[S::INSTITUTION_TYPE_ID]);
                $ins[S::INSTITUTION_MODULES_IDS] = array_values(array_unique([...($ins[S::INSTITUTION_MODULES_IDS] ?? M::property($it, S::INSTITUTION_TYPE_MODULE_IDS, [])),$module]));
                $db->table(B::TABLE_SYSTEM)->where('recordId', M::id($institution))->update(['jsonData' => json_encode((object)$ins, JSON_THROW_ON_ERROR)]);
            }
            if ($changed || $db->table(B::TABLE_SYSTEM)->countAllResults() !== $beforeCount) {
                (new MetadataRevision($db))->publish();
            }
            if (!$db->transStatus() || !$db->transCommit()) {
                throw new \RuntimeException('Cement installation failed');
            }$begun = false;
            CLI::write('Installed/preserved '.count($ids).' Cement schemas. Financial posting remains guarded.');
        } catch (\Throwable $e) {
            if ($begun) {
                $db->transRollback();
            }throw $e;
        } finally {
            if ($locked) {
                $db->query('SELECT RELEASE_LOCK(?)', [MutationKeys::COMMIT_LOCK_NAME]);
            }
        }
    }
}
