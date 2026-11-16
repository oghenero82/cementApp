<?php

namespace App\Libraries\Cement;

/** Reservations are commitments, never stock movements or manual on-hand edits. */
final class Reservations
{
    public function __construct(private Store $s)
    {
    }
    private function decode(array $row): array
    {
        return $this->s->catalog->decode($row['type'], $row['data']);
    }
    private function stock(int $i, int $location, int $product): array
    {
        foreach ($this->s->all($i, 'inventory_stock') as $r) {
            $d = $this->decode($r);
            if ($d['LOCATION_ID'] === $location && $d['PRODUCT_ID'] === $product && $d['STOCK_CONDITION'] === 'good') {
                return [$r['id'],$d];
            }
        }
        throw new Problem('No good stock exists at the selected warehouse');
    }
    public function guard(array $d, array $old, array $patch, string $op): void
    {
        if ($op === 'delete') {
            throw new Problem('Release a reservation to retain its audit history');
        }
        if ($old) {
            if ($old['STATUS'] !== 'active') {
                throw new Problem('This reservation is no longer active', 409);
            }foreach ($patch as $alias => $value) {
                if ($alias !== 'STATUS' && $value !== ($old[$alias] ?? null)) {
                    throw new Problem('Reservation terms are immutable; release it and create a replacement');
                }
            }if (($d['STATUS'] ?? null) !== 'released') {
                throw new Problem('An active reservation can only be released');
            }
        } elseif (($d['STATUS'] ?? null) !== 'active' || array_key_exists('REMAINING_QTY', $patch)) {
            throw new Problem('New reservations must be active; remaining quantity is computed');
        }
    }
    public function prepare(int $i, array $d, array $old): array
    {
        if ($old) {
            $d['REMAINING_QTY'] = 0;
            return $d;
        }
        $qty = $d['QUANTITY_BASE'] ?? 0;
        if (!is_numeric($qty) || !is_finite((float)$qty) || $qty <= 0) {
            throw new Problem('Reserved quantity must be positive');
        }
        $sale = $this->s->semantic($i, 'customer_sales', (int)($d['SALE_ID'] ?? 0));
        if (!in_array($sale['STATUS'], ['draft','partially_invoiced']) || ($sale['WAREHOUSE_ID'] ?? null) !== ($d['LOCATION_ID'] ?? null)) {
            throw new Problem('Reserve against an open warehouse order at its declared location');
        }
        [$id,$stock] = $this->stock($i, (int)$d['LOCATION_ID'], (int)$d['PRODUCT_ID']);
        if ($qty > $stock['QUANTITY_AVAILABLE'] + 0.000001) {
            throw new Problem('Reservation exceeds available stock');
        }
        $ordered = 0;
        $issued = 0;
        $held = 0;
        foreach ($this->s->related($i, 'customer_sale_item', 'SALE_ID', $d['SALE_ID']) as $r) {
            $line = $this->decode($r);
            if ($line['PRODUCT_ID'] !== $d['PRODUCT_ID']) {
                continue;
            }$ordered += $line['QUANTITY_BASE'];
            foreach ($this->s->related($i, 'inventory_movement', 'SALE_ITEM_ID', $r['id']) as $m) {
                $move = $this->decode($m);
                if ($move['STATUS'] === 'confirmed' && !empty($move['SOURCE_LOCATION_ID'])) {
                    $issued += $move['QTY_BASE'];
                }
            }
        }
        foreach ($this->s->related($i, 'inventory_reservation', 'SALE_ID', $d['SALE_ID']) as $r) {
            $v = $this->decode($r);
            if ($v['STATUS'] === 'active' && $v['PRODUCT_ID'] === $d['PRODUCT_ID']) {
                $held += $v['REMAINING_QTY'];
            }
        }
        if ($qty + $held + $issued > $ordered + 0.000001) {
            throw new Problem('Reservation exceeds the unfulfilled order quantity');
        }$d['REMAINING_QTY'] = $qty;
        return $d;
    }
    public function rollup(int $i, int $location, int $product): void
    {
        [$id,$stock] = $this->stock($i, $location, $product);
        $held = 0;
        foreach ($this->s->all($i, 'inventory_reservation') as $r) {
            $d = $this->decode($r);
            if ($d['STATUS'] === 'active' && $d['LOCATION_ID'] === $location && $d['PRODUCT_ID'] === $product) {
                $held += $d['REMAINING_QTY'];
            }
        }
        if ($held > $stock['QUANTITY_ON_HAND'] + 0.000001) {
            throw new Problem('Reserved quantity exceeds on-hand stock');
        }
        $this->s->patch($i, 'inventory_stock', $id, ['QUANTITY_RESERVED' => $held,'QUANTITY_AVAILABLE' => $stock['QUANTITY_ON_HAND'] - $held]);
    }
    public function consume(int $i, int $sale, int $location, int $product, float $quantity): void
    {
        $changed = false;
        foreach ($this->s->related($i, 'inventory_reservation', 'SALE_ID', $sale) as $r) {
            $d = $this->decode($r);
            if ($d['STATUS'] !== 'active' || $d['LOCATION_ID'] !== $location || $d['PRODUCT_ID'] !== $product) {
                continue;
            }$used = min($quantity, $d['REMAINING_QTY']);
            $left = round($d['REMAINING_QTY'] - $used, 6);
            $this->s->patch($i, 'inventory_reservation', $r['id'], ['REMAINING_QTY' => $left,'STATUS' => $left > 0 ? 'active' : 'consumed']);
            $quantity -= $used;
            $changed = true;
            if ($quantity <= 0.000001) {
                break;
            }
        }
        if ($changed) {
            $this->rollup($i, $location, $product);
        }
    }
}
