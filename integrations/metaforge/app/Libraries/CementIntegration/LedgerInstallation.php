<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\{CatalogStore,CatalogReader,CatalogContract,Metadata as M};
use App\Libraries\MetaForge\Seeders\DomainCatalog;

/** Checked migration of the shipped shared Accounting definitions, never duplicate RecordTypes. */
final class LedgerInstallation
{
    public static function install($db, CatalogStore $store, CatalogReader $catalog, int $physical): bool
    {
        $source = (new DomainCatalog())->definitions($physical);
        $root = M::id($catalog->definition('record_type'));
        $changed = false;
        foreach (['journal','journal_line','account_balance','account'] as $type) {
            $old = $source[$type];
            $new = $old;
            $new['settings']['cementVerifiedLedger'] = 1;
            if ($type === 'account') {
                $new['settings']['cementLookupLabel'] = 'name';
            } else {
                $new['services'] = array_values(array_filter($new['services'], static fn ($hook) => !in_array($hook['code'], ['financial_effect_guard','balance_recalculation'], true)));
                $new['services'][] = ['code' => 'cement_verified_ledger','service' => 'cement.ledger.validate','method' => 'validate','stage' => $type === 'account_balance' ? 'beforeValidate' : 'beforeSave','trigger' => 'true','order' => -900,'parameters' => ['recordTypeCode' => $type,'record' => ['source' => 'data'],'previous' => ['source' => 'old'],'operation' => ['source' => 'operation']]];
                if ($type === 'journal') {
                    $new['services'][] = ['code' => 'cement_ledger_balances','service' => 'cement.ledger.balances','method' => 'balances','stage' => 'afterSave','trigger' => 'true','order' => 1000,'parameters' => ['record' => ['source' => 'data'],'recordId' => ['source' => 'record','key' => 'recordId']]];
                }
                if ($type === 'account_balance') {
                    $new['actions'] = ['read','insert','update'];
                }
            }
            $row = $db->table('mf_system')->where('recordCode', $type)->get()->getRowArray();
            if (!$row) {
                throw new \RuntimeException('Install shared Accounting before enabling purchase funding');
            }$data = json_decode($row['jsonData'], true, 64, JSON_THROW_ON_ERROR);
            if (!isset($data['settings']['cementVerifiedLedger'])) {
                $store->ensure($type, $root, $old);
                $new = CatalogContract::normalize('record_type', $new, $catalog);
                $db->table('mf_system')->where('recordId', $row['recordId'])->update(['jsonData' => json_encode((object)$new, JSON_THROW_ON_ERROR)]);
                $changed = true;
            }
            $store->ensure($type, $root, $new);
        }return $changed;
    }
    public static function purchase(array $definition): array
    {
        if ($definition['code'] !== 'purchase') {
            return $definition;
        }
        $definition['settings']['cementPurchaseFunding'] = 1;
        $definition['services'][] = ['code' => 'cement_purchase_fund','service' => 'cement.purchase.fund','method' => 'fund','stage' => 'beforeSave','trigger' => 'true','order' => 1000,'parameters' => ['record' => ['source' => 'data'],'previous' => ['source' => 'old'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        return $definition;
    }
}
