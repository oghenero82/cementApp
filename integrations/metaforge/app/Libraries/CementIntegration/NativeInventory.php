<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Immutable movements plus deterministic stock/layer projections in the native outer transaction. */
final class NativeInventory
{
    public const TYPES = ['inventory_movement','inventory_stock','inventory_cost_layer'];
    public static function register($registry): void
    {
        $h = new self();
        $registry->register('cement.inventory.prepare', $h, 'prepare', [['name' => 'recordTypeCode','type' => 'string'],['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']]);
        $registry->register('cement.inventory.effects', $h, 'effects', [['name' => 'record','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']]);
    }
    public static function definition(array $d): array
    {
        $d['settings']['cementInventory'] = 1;
        $d['settings']['cementOperations'] = $d['code'] === 'inventory_movement' ? ['read','insert','update','delete'] : ['read'];
        $d['services'][] = ['code' => 'cement_inventory_prepare','service' => 'cement.inventory.prepare','method' => 'prepare','stage' => 'beforeValidate','trigger' => 'true','order' => -1000,'parameters' => ['recordTypeCode' => $d['code'],'record' => ['source' => 'data'],'previous' => ['source' => 'old'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        if ($d['code'] === 'inventory_movement') {
            $d['services'][] = ['code' => 'cement_inventory_effects','service' => 'cement.inventory.effects','method' => 'effects','stage' => 'afterSave','trigger' => 'true','order' => 1000,'parameters' => ['record' => ['source' => 'data'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        }
        return $d;
    }
    private function history(NativeRecords $r): array
    {
        $rows = $r->rows('inventory_movement');
        usort($rows, fn ($a, $b) => [$a['EFFECTIVE_AT'] ?? $a['MOVEMENT_DATE'],$a['_MUTATION'],$a['_ID']] <=> [$b['EFFECTIVE_AT'] ?? $b['MOVEMENT_DATE'],$b['_MUTATION'],$b['_ID']]);
        return $rows;
    }
    private function reserved(NativeRecords $r, int $location, int $product): float
    {
        if (!isset($r->context->catalog->all()['recordTypes']['meta']['inventory_reservation'])) {
            return 0;
        }
        $held = 0;
        foreach ($r->rows('inventory_reservation') as $row) {
            if ($row['LOCATION_ID'] === $location && $row['PRODUCT_ID'] === $product && $row['STATUS'] === 'active') {
                $held += (float)$row['REMAINING_QTY'];
            }
        }return $held;
    }
    public function prepare(string $recordTypeCode, array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $d = $r->decode($recordTypeCode, $record);
        $old = $r->decode($recordTypeCode, $previous);
        if ($recordTypeCode === 'inventory_movement') {
            if (($old['STATUS'] ?? '') === 'confirmed') {
                throw ValidationException::field($r->aliases($recordTypeCode)['STATUS'], 'Confirmed inventory movements are immutable');
            }
            if ($operation === 'delete') {
                return [];
            }
            $d['STATUS'] ??= 'draft';
            if (!in_array($d['STATUS'], ['draft','confirmed'], true)) {
                throw ValidationException::field('status', 'Invalid movement state');
            }
            if (!in_array(TrustedPlans::origin(), ['cement.loading','cement.sales','cement.stocktake','cement.transfer','cement.damage','cement.return'], true)) {
                foreach (['SOURCE_RECORD_TYPE_CODE','SOURCE_RECORD_ID','PURCHASE_ITEM_ID','LOADING_ID','SALE_ITEM_ID'] as $field) {
                    if (!empty($d[$field])) {
                        throw ValidationException::field('source', 'Use the connected business action for linked stock movements');
                    }
                }
            }
            $policy = $r->settings()['inventoryValuationMethod'] ?? 'weighted_average';
            $d['COST_POLICY'] ??= $policy;
            if ($d['COST_POLICY'] !== $policy) {
                throw ValidationException::field($r->aliases($recordTypeCode)['COST_POLICY'], 'Movement policy must match the institution valuation method');
            }
            $d['TOTAL_COST'] = '0.00';
            $d['FIFO_ALLOCATIONS'] = [];
            if ($d['STATUS'] === 'draft') {
                return ['proposedData' => $r->encode($recordTypeCode, ['COST_POLICY' => $policy,'TOTAL_COST' => '0.00','FIFO_ALLOCATIONS' => []])];
            }
            if (($d['MOVEMENT_DATE'] ?? '') > gmdate('Y-m-d H:i:s')) {
                throw ValidationException::field($r->aliases($recordTypeCode)['MOVEMENT_DATE'], 'Future stock effects must remain draft');
            }
            (new NativeLedger())->authorizePosting($context);
            $history = $this->history($r);
            $history = array_values(array_filter($history, fn ($m) => $m['_ID'] !== $recordId));
            $d['_ID'] = $recordId ?: -1;
            $d['_MUTATION'] = $context->mutationId;
            $d['EFFECTIVE_AT'] = gmdate('Y-m-d H:i:s');
            if (!empty($d['SOURCE_LOCATION_ID'])) {
                $existing = InventoryMath::project($history);
                $key = InventoryMath::key($d['SOURCE_LOCATION_ID'], $d['PRODUCT_ID'], $d['STOCK_CONDITION']);
                $available = ($existing['stocks'][$key]['qty'] ?? 0) - $this->reserved($r, $d['SOURCE_LOCATION_ID'], $d['PRODUCT_ID']);
                if ((float)$d['QTY_BASE'] > $available + 0.000001) {
                    throw ValidationException::field($r->aliases($recordTypeCode)['QTY_BASE'], 'Insufficient unreserved stock');
                }
            }
            try {
                $projection = InventoryMath::project([...$history,$d]);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::field($r->aliases($recordTypeCode)['QTY_BASE'], $e->getMessage());
            }
            $effect = $projection['effects'][$d['_ID']];
            $computed = ['UNIT_COST' => $effect['unitCost'],'TOTAL_COST' => NativeLedger::decimal($effect['minor']),'FIFO_ALLOCATIONS' => $effect['allocations'],'EFFECTIVE_AT' => $d['EFFECTIVE_AT']];
            $person = $r->actorPerson();
            if ($person !== null) {
                $computed['AUTHORIZED_BY'] = $person;
            } elseif (isset($d['AUTHORIZED_BY'])) {
                throw ValidationException::field('authorizedBy', 'Link the approving user to a staff person first');
            }
            return ['proposedData' => $r->encode($recordTypeCode, $computed)];
        }
        if ($operation === 'delete') {
            throw ValidationException::field('record', 'Stock projections cannot be deleted');
        }
        if ($previous) {
            foreach ($recordTypeCode === 'inventory_stock' ? ['LOCATION_ID','PRODUCT_ID','STOCK_CONDITION'] : ['STOCK_ID','SOURCE_MOVEMENT_ID'] as $field) {
                if (($old[$field] ?? null) !== ($d[$field] ?? null)) {
                    throw ValidationException::field('record', 'Stock projection identity is immutable');
                }
            }
        }
        $projection = InventoryMath::project($this->history($r));
        if ($recordTypeCode === 'inventory_stock') {
            $key = InventoryMath::key($d['LOCATION_ID'], $d['PRODUCT_ID'], $d['STOCK_CONDITION']);
            $s = $projection['stocks'][$key] ?? ['qty' => 0,'minor' => 0,'last' => null];
            $held = $d['STOCK_CONDITION'] === 'good' ? $this->reserved($r, $d['LOCATION_ID'], $d['PRODUCT_ID']) : 0;
            if ($held > $s['qty'] + 0.000001) {
                throw ValidationException::field('record', 'Reserved quantity exceeds on-hand stock');
            }
            $data = ['CODE' => 'STOCK-'.$d['LOCATION_ID'].'-'.$d['PRODUCT_ID'].'-'.$d['STOCK_CONDITION'],'QUANTITY_ON_HAND' => $s['qty'],'QUANTITY_RESERVED' => $held,'QUANTITY_AVAILABLE' => $s['qty'] - $held,'AVERAGE_UNIT_COST' => $s['qty'] > 0 ? $s['minor'] / 100 / $s['qty'] : 0,'TOTAL_VALUE' => NativeLedger::decimal($s['minor']),'STATUS' => 'active'];
            if ($s['last']) {
                $data['LAST_MOVEMENT_AT'] = $s['last'];
            }
            return ['proposedData' => $r->encode($recordTypeCode, $data)];
        }
        $stock = $r->find('inventory_stock', (int)$d['STOCK_ID']);
        $key = InventoryMath::key($stock['LOCATION_ID'], $stock['PRODUCT_ID'], $stock['STOCK_CONDITION']);
        $layer = null;
        // Resolve by the declared semantic alias, never by a client-supplied layer quantity.
        foreach ($projection['stocks'][$key]['layers'] ?? [] as $candidate) {
            if ($candidate['source'] === ($d['SOURCE_MOVEMENT_ID'] ?? null)) {
                $layer = $candidate;
            }
        }
        if (!$layer) {
            throw ValidationException::field('sourceMovementId', 'Layer requires an effective receipt at this stock location');
        }
        return ['proposedData' => $r->encode($recordTypeCode, ['CODE' => 'LAYER-'.$d['SOURCE_MOVEMENT_ID'].'-'.$d['STOCK_ID'],'PRODUCT_ID' => $stock['PRODUCT_ID'],'LOCATION_ID' => $stock['LOCATION_ID'],'RECEIPT_DATE' => $layer['date'],'ORIGINAL_QTY' => $layer['original'],'REMAINING_QTY' => $layer['remaining'],'UNIT_COST' => $layer['originalMinor'] / 100 / $layer['original'],'STATUS' => $layer['remaining'] > 0 ? 'active' : 'consumed'])];
    }
    public function effects(array $record, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $d = $r->decode('inventory_movement', $record);
        if ($operation === 'delete' || ($d['STATUS'] ?? '') !== 'confirmed') {
            return [];
        }
        $projection = InventoryMath::project($this->history($r));
        $stocks = $r->rows('inventory_stock');
        $layers = $r->rows('inventory_cost_layer');
        $plans = [];
        foreach (array_filter([$d['SOURCE_LOCATION_ID'] ?? 0,$d['DESTINATION_LOCATION_ID'] ?? 0]) as $location) {
            $key = InventoryMath::key($location, $d['PRODUCT_ID'], $d['STOCK_CONDITION']);
            $s = $projection['stocks'][$key];
            $existing = null;
            foreach ($stocks as $stock) {
                if ($stock['LOCATION_ID'] === $location && $stock['PRODUCT_ID'] === $d['PRODUCT_ID'] && $stock['STOCK_CONDITION'] === $d['STOCK_CONDITION']) {
                    $existing = $stock;
                    break;
                }
            }
            $ref = 'stock_'.substr(hash('sha256', $key), 0, 32);
            $p = $r->plan('inventory_stock', ['LOCATION_ID' => $location,'PRODUCT_ID' => $d['PRODUCT_ID'],'STOCK_CONDITION' => $d['STOCK_CONDITION']], $existing['_ID'] ?? null);
            $p['_cementClientRef'] = $ref;
            $plans[] = $p;
            foreach ($s['layers'] as $layer) {
                $old = null;
                foreach ($layers as $row) {
                    if ($row['SOURCE_MOVEMENT_ID'] === $layer['source'] && $row['LOCATION_ID'] === $location && $row['PRODUCT_ID'] === $d['PRODUCT_ID']) {
                        $old = $row;
                        break;
                    }
                }
                if ($old && abs((float)$old['REMAINING_QTY'] - $layer['remaining']) < 0.0000001) {
                    continue;
                }
                $data = ['SOURCE_MOVEMENT_ID' => $layer['source']];
                if ($old) {
                    $data['STOCK_ID'] = $old['STOCK_ID'];
                }$p = $r->plan('inventory_cost_layer', $data, $old['_ID'] ?? null);
                if (!$old) {
                    $p['_cementReferences'][$r->aliases('inventory_cost_layer')['STOCK_ID']] = $ref;
                }$plans[] = $p;
            }
        }
        $amount = $projection['effects'][$recordId]['minor'];
        if ($amount > 0 && (!empty($d['SOURCE_LOCATION_ID']) xor !empty($d['DESTINATION_LOCATION_ID']))) {
            $settings = $r->settings();
            $inventory = (int)($settings['cementInventoryAccountId'] ?? 0);
            $offset = (int)($settings['cementStockVarianceAccountId'] ?? 0);
            if (!empty($d['PURCHASE_ITEM_ID'])) {
                $item = $r->find('purchaseItem', $d['PURCHASE_ITEM_ID']);
                $purchase = $r->find('purchase', $item['PURCHASE_ID']);
                $offset = (int)($purchase['PREPAID_ACCOUNT_ID'] ?? 0);
            }
            if (!empty($d['SALE_ITEM_ID'])) {
                $offset = (int)($settings['cementCostOfSalesAccountId'] ?? 0);
            }
            if (!$inventory || !$offset || $inventory === $offset) {
                throw ValidationException::field('accounts', 'Configure different inventory and stock-offset GL accounts');
            }
            $debit = !empty($d['DESTINATION_LOCATION_ID']) ? $inventory : $offset;
            $credit = !empty($d['DESTINATION_LOCATION_ID']) ? $offset : $inventory;
            $plans = [...$plans,...(new NativeLedger())->postingPlans($context, 'inventory_movement', $recordId, 'CEMENT-STOCK-'.$recordId, 'Inventory movement '.$d['CODE'], [[$debit,$amount,0],[$credit,0,$amount]])];
        }
        return ['mutationPlans' => $plans];
    }
}
