<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Reservations hold available good stock and are consumed only by their own sale. */
final class NativeReservations
{
    public const TYPES = ['inventory_reservation'];
    public static function enabled(NativeRecords $r): bool
    {
        return isset($r->context->catalog->all()['recordTypes']['meta']['inventory_reservation']);
    }
    public static function register($registry): void
    {
        $h = new self();
        $inputs = [['name' => 'record','type' => 'object'],['name' => 'previous','type' => 'object'],['name' => 'recordId','type' => 'integer'],['name' => 'operation','type' => 'string']];
        foreach (['prepare','effects'] as $method) {
            $registry->register('cement.reservations.'.$method, $h, $method, $inputs);
        }
    }
    public static function definition(array $d): array
    {
        $d['settings']['cementReservations'] = 1;
        $d['settings']['cementOperations'] = ['read','insert','update'];
        foreach (['prepare' => 'beforeValidate','effects' => 'afterSave'] as $method => $stage) {
            $d['services'][] = ['code' => 'cement_reservations_'.$method,'service' => 'cement.reservations.'.$method,'method' => $method,'stage' => $stage,'trigger' => 'true','order' => $method === 'prepare' ? -1000 : 1000,'parameters' => ['record' => ['source' => 'data'],'previous' => ['source' => 'old'],'recordId' => ['source' => 'record','key' => 'recordId'],'operation' => ['source' => 'operation']]];
        }return $d;
    }
    public static function guardLine(NativeRecords $r, array $old, array $d, string $operation): void
    {
        if (!$old || !self::enabled($r)) {
            return;
        }$changed = $operation === 'delete';
        foreach (['PRODUCT_ID','UNIT_ID','QUANTITY'] as $a) {
            if (($old[$a] ?? null) != ($d[$a] ?? null)) {
                $changed = true;
            }
        }if (!$changed) {
            return;
        }
        foreach ($r->rows('inventory_reservation') as $hold) {
            if ($hold['SALE_ID'] === $old['SALE_ID'] && $hold['PRODUCT_ID'] === $old['PRODUCT_ID'] && $hold['STATUS'] === 'active') {
                throw ValidationException::field('quantity', 'Release reservations before changing the order quantity or product');
            }
        }
    }
    public static function consumePlans(NativeRecords $r, int $sale, int $location, int $product, float $quantity): array
    {
        if (!self::enabled($r)) {
            return [];
        }$rows = $r->rows('inventory_reservation');
        usort($rows, fn ($a, $b) => $a['_ID'] <=> $b['_ID']);
        $plans = [];
        foreach ($rows as $hold) {
            if ($hold['SALE_ID'] === $sale && $hold['LOCATION_ID'] === $location && $hold['PRODUCT_ID'] === $product && $hold['STATUS'] === 'active') {
                $used = min($quantity, (float)$hold['REMAINING_QTY']);
                if ($used <= 0) {
                    continue;
                }$left = round((float)$hold['REMAINING_QTY'] - $used, 6);
                $p = $r->plan('inventory_reservation', ['REMAINING_QTY' => $left,'STATUS' => $left > 0 ? 'active' : 'consumed'], $hold['_ID']);
                $p['_cementGeneratedBy'] = 'cement.reservation.consume';
                $plans[] = $p;
                $quantity -= $used;
                if ($quantity <= 0.00000001) {
                    break;
                }
            }
        }return $plans;
    }
    public function prepare(array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $d = $r->decode('inventory_reservation', $record);
        $old = $r->decode('inventory_reservation', $previous);
        $f = $r->aliases('inventory_reservation');
        if ($operation === 'delete') {
            throw ValidationException::field($f['STATUS'], 'Release a reservation to retain its history');
        }
        if ($old) {
            if ($old['STATUS'] !== 'active') {
                throw ValidationException::field($f['STATUS'], 'Reservation is no longer active');
            }foreach ($old as $a => $v) {
                if (!in_array($a, ['REMAINING_QTY','STATUS'], true) && ($d[$a] ?? null) != $v) {
                    throw ValidationException::field($f['STATUS'], 'Reservation terms are immutable');
                }
            }
            if (TrustedPlans::origin() === 'cement.reservation.consume') {
                $left = (float)$d['REMAINING_QTY'];
                if ($left < 0 || $left >= (float)$old['REMAINING_QTY'] || ($d['STATUS'] !== ($left > 0 ? 'active' : 'consumed'))) {
                    throw ValidationException::field($f['REMAINING_QTY'], 'Invalid reservation consumption');
                }return [];
            }
            if ($d['STATUS'] !== 'released') {
                throw ValidationException::field($f['STATUS'], 'An active reservation can only be released');
            }return ['proposedData' => $r->encode('inventory_reservation', ['REMAINING_QTY' => 0])];
        }
        if (($d['STATUS'] ?? 'active') !== 'active') {
            throw ValidationException::field($f['STATUS'], 'New reservations must be active');
        }$qty = (float)($d['QUANTITY_BASE'] ?? 0);
        if (!is_finite($qty) || $qty <= 0 || abs($qty - round($qty, 6)) > 0.00000001) {
            throw ValidationException::field($f['QUANTITY_BASE'], 'Use a positive quantity with at most six decimals');
        }$sale = $r->find('customer_sales', (int)$d['SALE_ID']);
        if (!in_array($sale['STATUS'], ['draft','invoiced'], true) || ($sale['WAREHOUSE_ID'] ?? null) !== $d['LOCATION_ID'] || !empty($sale['SOURCE_LOADING_ID'])) {
            throw ValidationException::field($f['SALE_ID'], 'Reserve against an open warehouse order at its location');
        }
        $stock = null;
        foreach ($r->rows('inventory_stock') as $s) {
            if ($s['LOCATION_ID'] === $d['LOCATION_ID'] && $s['PRODUCT_ID'] === $d['PRODUCT_ID'] && $s['STOCK_CONDITION'] === 'good') {
                $stock = $s;
            }
        }if (!$stock || $qty > (float)$stock['QUANTITY_AVAILABLE'] + 0.00000001) {
            throw ValidationException::field($f['QUANTITY_BASE'], 'Reservation exceeds available good stock');
        }$ordered = 0;
        $issued = 0;
        $held = 0;
        $items = [];
        foreach ($r->rows('customer_sale_item') as $line) {
            if ($line['SALE_ID'] === $sale['_ID'] && $line['PRODUCT_ID'] === $d['PRODUCT_ID']) {
                $ordered += (float)$line['QUANTITY_BASE'];
                $items[$line['_ID']] = true;
            }
        }
        foreach ($r->rows('inventory_movement') as $m) {
            if (isset($items[$m['SALE_ITEM_ID'] ?? 0]) && $m['STATUS'] === 'confirmed' && !empty($m['SOURCE_LOCATION_ID'])) {
                $issued += (float)$m['QTY_BASE'];
            }
        }foreach ($r->rows('inventory_reservation') as $hold) {
            if ($hold['SALE_ID'] === $sale['_ID'] && $hold['PRODUCT_ID'] === $d['PRODUCT_ID'] && $hold['STATUS'] === 'active') {
                $held += (float)$hold['REMAINING_QTY'];
            }
        }if ($qty + $held + $issued > $ordered + 0.00000001) {
            throw ValidationException::field($f['QUANTITY_BASE'], 'Reservation exceeds unfulfilled order quantity');
        }return ['proposedData' => $r->encode('inventory_reservation', ['STATUS' => 'active','REMAINING_QTY' => $qty])];
    }
    public function effects(array $record, array $previous, int $recordId, string $operation, MutationContext $context): array
    {
        $r = new NativeRecords($context);
        $d = $r->decode('inventory_reservation', $record);
        foreach ($r->rows('inventory_stock') as $s) {
            if ($s['LOCATION_ID'] === $d['LOCATION_ID'] && $s['PRODUCT_ID'] === $d['PRODUCT_ID'] && $s['STOCK_CONDITION'] === 'good') {
                $p = $r->plan('inventory_stock', ['CODE' => $s['CODE']], $s['_ID']);
                $p['_cementGeneratedBy'] = 'cement.reservation';
                return ['mutationPlans' => [$p]];
            }
        }throw ValidationException::field('stock','Reservation stock is unavailable');
    }
}
