<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Physical counts, approved variances, atomic transfers and damaged-stock decisions. */
final class NativeStockControls
{
    public const TYPES = ['inventory_stocktake','inventory_stocktake_line','stock_adjustment','inventory_transfer','damaged_goods'];
    public static function register($registry): void
    {
        $h = new self();
        $inputs = [['name' => 'recordTypeCode','type' => 'string'],['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']];
        foreach (['prepare','effects'] as $method) {
            $registry->register('cement.stockControls.'.$method, $h, $method, $inputs);
        }
    }
    public static function definition(array $d): array
    {
        $d['settings']['cementStockControls'] = 1;
        $d['settings']['cementOperations'] = ['read','insert','update','delete'];
        foreach (['prepare' => 'beforeValidate','effects' => 'afterSave'] as $method => $stage) {
            $d['services'][] = ['code' => 'cement_stock_controls_'.$method,'service' => 'cement.stockControls.'.$method,'method' => $method,'stage' => $stage,'trigger' => 'true','order' => $method === 'prepare' ? -1000 : 1000,'parameters' => ['recordTypeCode' => $d['code'],'record' => ['source' => 'data'],'previous' => ['source' => 'old'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        }return $d;
    }
    private static function stock(NativeRecords $r, int $location, int $product, string $condition): array
    {
        foreach ($r->rows('inventory_stock') as $s) {
            if ($s['LOCATION_ID'] === $location && $s['PRODUCT_ID'] === $product && $s['STOCK_CONDITION'] === $condition) {
                return $s;
            }
        }
        return ['QUANTITY_ON_HAND' => 0,'AVERAGE_UNIT_COST' => 0,'TOTAL_VALUE' => '0.00'];
    }
    private static function positive(mixed $value): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0 || abs((float)$value - round((float)$value, 6)) > 0.00000001) {
            throw ValidationException::field('quantity', 'Use a positive quantity with at most six decimal places');
        }return (float)$value;
    }
    private static function movement(NativeRecords $r, string $type, int $id, array $data, string $origin): array
    {
        $p = $r->plan('inventory_movement', array_replace(['CODE' => 'CONTROL-'.$type.'-'.$id.'-'.($data['STOCK_CONDITION'] ?? 'good'),'MOVEMENT_DATE' => gmdate('Y-m-d H:i:s'),'MOVEMENT_TYPE' => $type,'STOCK_CONDITION' => 'good','UNIT_COST' => 0,'COST_POLICY' => $r->settings()['inventoryValuationMethod'] ?? 'weighted_average','SOURCE_RECORD_TYPE_CODE' => $type,'SOURCE_RECORD_ID' => $id,'STATUS' => 'confirmed'], $data));
        $p['_cementGeneratedBy'] = $origin;
        return $p;
    }
    private static function cost(NativeRecords $r, int $location, int $product, string $condition, float $qty): int
    {
        $rows = $r->rows('inventory_movement');
        usort($rows, fn ($a, $b) => [$a['EFFECTIVE_AT'] ?? $a['MOVEMENT_DATE'],$a['_MUTATION'],$a['_ID']] <=> [$b['EFFECTIVE_AT'] ?? $b['MOVEMENT_DATE'],$b['_MUTATION'],$b['_ID']]);
        $m = ['_ID' => -1,'STATUS' => 'confirmed','MOVEMENT_DATE' => gmdate('Y-m-d H:i:s'),'PRODUCT_ID' => $product,'STOCK_CONDITION' => $condition,'SOURCE_LOCATION_ID' => $location,'QTY_BASE' => $qty,'COST_POLICY' => $r->settings()['inventoryValuationMethod'] ?? 'weighted_average'];
        try {
            return InventoryMath::project([...$rows,$m])['effects'][-1]['minor'];
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::field('quantity', $e->getMessage());
        }
    }
    public function prepare(string $recordTypeCode, array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $t = $recordTypeCode;
        $d = $r->decode($t, $record);
        $old = $r->decode($t, $previous);
        $f = $r->aliases($t);
        $final = match($t) {
            'inventory_stocktake' => 'posted','inventory_transfer' => 'received',default => 'confirmed'
        };
        if ($old && ($old['STATUS'] ?? 'draft') !== 'draft') {
            throw ValidationException::field($f['STATUS'], 'Posted inventory controls are immutable');
        }
        if ($t === 'inventory_stocktake_line') {
            if ($old && ($old['STOCKTAKE_ID'] !== $d['STOCKTAKE_ID'] || $old['PRODUCT_ID'] !== $d['PRODUCT_ID'] || $old['STOCK_CONDITION'] !== $d['STOCK_CONDITION'])) {
                throw ValidationException::field($f['STOCKTAKE_ID'], 'Stocktake parent is immutable');
            }$take = $r->find('inventory_stocktake', (int)$d['STOCKTAKE_ID']);
            if ($take['STATUS'] !== 'draft') {
                throw ValidationException::field($f['STOCKTAKE_ID'], 'Posted stocktake lines are immutable');
            }if ($operation === 'delete') {
                return [];
            }
            if (($d['STATUS'] ?? 'draft') !== 'draft') {
                throw ValidationException::field($f['STATUS'], 'Count lines remain draft until their stocktake is posted');
            }$count = $d['COUNTED_QTY'] ?? -1;
            if (!is_numeric($count) || !is_finite((float)$count) || $count < 0 || abs((float)$count - round((float)$count, 6)) > 0.00000001) {
                throw ValidationException::field($f['COUNTED_QTY'], 'Count must be nonnegative with at most six decimals');
            }$s = self::stock($r, $take['LOCATION_ID'], $d['PRODUCT_ID'], $d['STOCK_CONDITION']);
            $qty = $old['SYSTEM_QTY'] ?? $s['QUANTITY_ON_HAND'];
            $cost = (float)($s['AVERAGE_UNIT_COST'] ?: ($d['UNIT_COST'] ?? 0));
            if (!is_finite($cost) || $cost < 0) {
                throw ValidationException::field($f['UNIT_COST'], 'Invalid count unit cost');
            }$delta = (float)$count - (float)$qty;
            $value = $delta < 0 ? -self::cost($r, $take['LOCATION_ID'], $d['PRODUCT_ID'], $d['STOCK_CONDITION'], abs($delta)) : (int)round($delta * $cost * 100);
            if ($delta != 0) {
                $cost = abs($value) / 100 / abs($delta);
            }$encoded = $r->encode($t, ['SYSTEM_QTY' => $qty,'VARIANCE_QTY' => $delta,'UNIT_COST' => $cost,'VARIANCE_VALUE' => round($value / 100, 2)]);
            $encoded['settings'] = ['cement' => ['stockValueMinor' => $previous['settings']['cement']['stockValueMinor'] ?? NativeLedger::minor($s['TOTAL_VALUE'])]];
            return ['proposedData' => $encoded];
        }
        if ($operation === 'delete') {
            return [];
        }$state = $d['STATUS'] ?? 'draft';
        if (!in_array($state, ['draft',$final], true)) {
            throw ValidationException::field($f['STATUS'], 'Invalid inventory control state');
        }$data = [];
        if ($t === 'inventory_stocktake') {
            if ($old && $old['LOCATION_ID'] !== $d['LOCATION_ID']) {
                foreach ($r->rows('inventory_stocktake_line') as $line) {
                    if ($line['STOCKTAKE_ID'] === $recordId) {
                        throw ValidationException::field($f['LOCATION_ID'], 'A count with lines cannot change location');
                    }
                }
            }
            $data['INVENTORY_KEEPING_METHOD'] = $r->settings()['inventoryKeepingMethod'] ?? 'manual_stocktaken';
            if ($state === 'posted') {
                if ($d['COUNT_DATE'] > gmdate('Y-m-d H:i:s')) {
                    throw ValidationException::field($f['COUNT_DATE'], 'Future counts must remain draft');
                }$lines = array_filter($r->rows('inventory_stocktake_line'), fn ($x) => $x['STOCKTAKE_ID'] === $recordId);
                if (!$lines) {
                    throw ValidationException::field($f['STATUS'], 'Add count lines before posting');
                }$seen = [];
                foreach ($lines as $line) {
                    $key = $line['PRODUCT_ID'].':'.$line['STOCK_CONDITION'];
                    if (isset($seen[$key])) {
                        throw ValidationException::field($f['STATUS'], 'Use one count per product and condition');
                    }$seen[$key] = true;
                    $s = self::stock($r, $d['LOCATION_ID'], $line['PRODUCT_ID'], $line['STOCK_CONDITION']);
                    if ((abs((float)$s['QUANTITY_ON_HAND'] - (float)$line['SYSTEM_QTY']) > 0.000001 || NativeLedger::minor($s['TOTAL_VALUE']) !== ($line['_PRIVATE']['stockValueMinor'] ?? null))) {
                        throw ValidationException::field($f['STATUS'], 'Stock changed since counting; start a new count');
                    }
                }
            }
        } elseif ($t === 'stock_adjustment') {
            $delta = (float)($d['QUANTITY_DELTA'] ?? 0);
            self::positive(abs($delta));
            if (isset($d['SOURCE_MOVEMENT_ID']) || (isset($d['STOCKTAKE_LINE_ID']) && TrustedPlans::origin() !== 'cement.stocktake')) {
                throw ValidationException::field($f['STATUS'], 'Adjustment provenance is server-owned');
            }if ($state === 'confirmed' && $d['ADJUSTMENT_DATE'] > gmdate('Y-m-d H:i:s')) {
                throw ValidationException::field($f['ADJUSTMENT_DATE'], 'Future adjustments must remain draft');
            }$s = self::stock($r, $d['LOCATION_ID'], $d['PRODUCT_ID'], $d['STOCK_CONDITION']);
            $cost = (float)($d['UNIT_COST'] ?? $s['AVERAGE_UNIT_COST']);
            if (!is_finite($cost) || $cost < 0) {
                throw ValidationException::field($f['UNIT_COST'], 'Use a nonnegative cost');
            }$minor = $delta < 0 ? self::cost($r, $d['LOCATION_ID'], $d['PRODUCT_ID'], $d['STOCK_CONDITION'], abs($delta)) : (int)round($cost * $delta * 100);
            $data += ['UNIT_COST' => $delta < 0 ? $minor / 100 / abs($delta) : $cost,'AMOUNT' => ($delta < 0 ? '-' : '').NativeLedger::decimal($minor)];
        } elseif ($t === 'inventory_transfer') {
            $qty = self::positive($d['QUANTITY'] ?? 0);
            if ($d['SOURCE_LOCATION_ID'] === $d['DESTINATION_LOCATION_ID']) {
                throw ValidationException::field($f['DESTINATION_LOCATION_ID'], 'Choose different locations');
            }foreach (['OUT_MOVEMENT_ID','IN_MOVEMENT_ID'] as $a) {
                if (isset($d[$a])) {
                    throw ValidationException::field($f[$a], 'Movement references are server-owned');
                }
            }$product = $r->find('product', $d['PRODUCT_ID']);
            $factor = $product['BASE_UNIT_ID'] === $d['UNIT_ID'] ? 1 : (($product['PACKAGE_UNIT_ID'] ?? null) === $d['UNIT_ID'] ? ($product['PACKAGE_SIZE'] ?? 0) : 0);
            if ($factor <= 0) {
                throw ValidationException::field($f['UNIT_ID'], 'Declare the product conversion first');
            }if ($state === 'received') {
                if ($d['REQUEST_DATE'] > gmdate('Y-m-d')) {
                    throw ValidationException::field($f['REQUEST_DATE'], 'Future transfers must remain draft');
                }$data += ['DISPATCHED_AT' => gmdate('Y-m-d H:i:s'),'RECEIVED_AT' => gmdate('Y-m-d H:i:s')];
            }return $this->approved($r, $t, $d, $data, $state === $final, $context, ['factor' => $factor]);
        } else {
            $qty = self::positive($d['QUANTITY'] ?? 0);
            if (isset($d['RELATED_MOVEMENT_ID']) || isset($d['WRITE_OFF_ADJUSTMENT_ID'])) {
                throw ValidationException::field($f['STATUS'], 'Damage provenance is server-owned');
            }if ($state === 'confirmed') {
                if (!in_array($d['DECISION'] ?? null, ['quarantine','write_off'], true) || (($d['DECISION'] ?? null) === 'quarantine' && $d['CONDITION'] !== 'good')) {
                    throw ValidationException::field($f['DECISION'], 'Quarantine good stock or write off damaged stock');
                }$minor = self::cost($r, $d['LOCATION_ID'], $d['PRODUCT_ID'], $d['CONDITION'], $qty);
                return $this->approved($r, $t, $d, $data, true, $context, ['costMinor' => $minor]);
            }
        }return $this->approved($r, $t, $d, $data, $state === $final, $context);
    }
    private function approved(NativeRecords $r, string $t, array $d, array $data, bool $final, MutationContext $context, ?array $private = null): array
    {
        if ($final) {
            (new NativeLedger())->authorizePosting($context);
            $field = $t === 'inventory_stocktake' ? 'POSTED_BY' : 'APPROVED_BY';
            $person = $r->actorPerson();
            if ($person !== null) {
                $data[$field] = $person;
            } elseif (isset($d[$field])) {
                throw ValidationException::field('person', 'Link the approving user to a staff person');
            }if ($t === 'stock_adjustment') {
                $data['APPROVAL_DATE'] = gmdate('Y-m-d H:i:s');
            }
        }$encoded = $r->encode($t, $data);
        if ($private !== null) {
            $encoded['settings'] = ['cement' => $private];
        }return ['proposedData' => $encoded];
    }
    public function effects(string $recordTypeCode, array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        if ($operation === 'delete') {
            return [];
        }$r = new NativeRecords($context);
        $t = $recordTypeCode;
        $d = $r->decode($t, $record);
        $state = $d['STATUS'] ?? 'draft';
        if ($state === 'draft' || $t === 'inventory_stocktake_line') {
            return [];
        }$plans = [];
        if ($t === 'inventory_stocktake') {
            foreach ($r->rows('inventory_stocktake_line') as $line) {
                if ($line['STOCKTAKE_ID'] === $recordId && abs((float)$line['VARIANCE_QTY']) > 0.000001) {
                    $p = $r->plan('stock_adjustment', ['CODE' => 'COUNT-'.$recordId.'-'.$line['_ID'],'LOCATION_ID' => $d['LOCATION_ID'],'PRODUCT_ID' => $line['PRODUCT_ID'],'ADJUSTMENT_DATE' => gmdate('Y-m-d H:i:s'),'REASON_CODE' => 'stocktake_variance','STOCK_CONDITION' => $line['STOCK_CONDITION'],'QUANTITY_DELTA' => $line['VARIANCE_QTY'],'UNIT_COST' => $line['UNIT_COST'],'STOCKTAKE_LINE_ID' => $line['_ID'],'STATUS' => 'confirmed']);
                    $p['_cementGeneratedBy'] = 'cement.stocktake';
                    $plans[] = $p;
                }
            }
        } elseif ($t === 'stock_adjustment') {
            $delta = (float)$d['QUANTITY_DELTA'];
            $plans[] = self::movement($r, $t, $recordId, ['PRODUCT_ID' => $d['PRODUCT_ID'],'STOCK_CONDITION' => $d['STOCK_CONDITION'],'QTY_BASE' => abs($delta),'UNIT_COST' => $d['UNIT_COST'],($delta > 0 ? 'DESTINATION_LOCATION_ID' : 'SOURCE_LOCATION_ID') => $d['LOCATION_ID']], 'cement.stocktake');
        } elseif ($t === 'inventory_transfer') {
            $plans[] = self::movement($r, $t, $recordId, ['PRODUCT_ID' => $d['PRODUCT_ID'],'QTY_BASE' => (float)$d['QUANTITY'] * (float)$record['settings']['cement']['factor'],'SOURCE_LOCATION_ID' => $d['SOURCE_LOCATION_ID'],'DESTINATION_LOCATION_ID' => $d['DESTINATION_LOCATION_ID']], 'cement.transfer');
        } else {
            $plans[] = self::movement($r, $t, $recordId, ['PRODUCT_ID' => $d['PRODUCT_ID'],'STOCK_CONDITION' => $d['CONDITION'],'QTY_BASE' => $d['QUANTITY'],'SOURCE_LOCATION_ID' => $d['LOCATION_ID']], 'cement.damage');
            if ($d['DECISION'] === 'quarantine') {
                $plans[] = self::movement($r,$t,$recordId,['PRODUCT_ID' => $d['PRODUCT_ID'],'STOCK_CONDITION' => 'damaged','QTY_BASE' => $d['QUANTITY'],'UNIT_COST' => (int)$record['settings']['cement']['costMinor'] / 100 / (float)$d['QUANTITY'],'DESTINATION_LOCATION_ID' => $d['LOCATION_ID']],'cement.damage');
            }
        }
        return ['mutationPlans' => $plans];
    }
}
