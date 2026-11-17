<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Database\RecordRepository;
use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Shared catalog changes must preserve the meaning of historical business snapshots. */
final class NativeMasterRules
{
    public static function validate(string $type, array $record, array $previous, MutationContext $ctx): void
    {
        if (!in_array($type, CatalogService::MASTERS, true)) {
            return;
        }$r = new NativeRecords($ctx);
        $d = $r->decode($type, $record);
        $old = $r->decode($type, $previous);
        $f = $r->aliases($type);
        if ($type === 'product' && $old && $old['BASE_UNIT_ID'] !== $d['BASE_UNIT_ID']) {
            // The authorized catalog update must respect history even when the actor cannot read
            // the business records. This existence check returns no transactional data to the actor.
            $repo = new RecordRepository(\Config\Database::connect());
            $meta = $ctx->catalog->metadata('product');
            $productId = null;
            foreach ($repo->query($repo->table($ctx, $meta), M::id($meta)) as $row) {
                if (M::property($row, $f['CODE']) === $old['CODE']) {
                    $productId = M::id($row);
                    break;
                }
            }
            foreach (['purchaseItem','customer_sale_item','inventory_movement'] as $target) {
                $tm = $ctx->catalog->metadata($target);
                $map = M::properties($tm)['settings']['cementFieldAliases'];
                foreach ($repo->query($repo->table($ctx, $tm), M::id($tm)) as $row) {
                    if (M::property($row, $map['PRODUCT_ID']) === $productId) {
                        throw ValidationException::field($f['BASE_UNIT_ID'], 'A product used in transactions cannot change its base unit');
                    }
                }
            }
        }
        foreach (match($type) {
            'customer' => ['CREDIT_LIMIT','PAYMENT_TERMS_DAYS'],'product' => ['PACKAGE_SIZE','PACKAGE_WEIGHT_KG','UNITS_PER_BAG','BAGS_PER_PALLET'],'price_list_item' => ['MIN_QUANTITY','MAX_QUANTITY','UNIT_PRICE'],default => []
        } as $a) {
            if (isset($d[$a]) && (!is_numeric($d[$a]) || !is_finite((float)$d[$a]) || $d[$a] < 0)) {
                throw ValidationException::field($f[$a], 'Use a nonnegative value');
            }
        }
        if (in_array($type, ['price_list','price_list_item'], true) && !empty($d['VALID_TO']) && !empty($d['VALID_FROM']) && $d['VALID_TO'] < $d['VALID_FROM']) {
            throw ValidationException::field($f['VALID_TO'], 'End date must follow start date');
        }
        if ($type === 'price_list_item' && ($d['MAX_QUANTITY'] ?? 0) > 0 && $d['MAX_QUANTITY'] < $d['MIN_QUANTITY']) {
            throw ValidationException::field($f['MAX_QUANTITY'], 'Maximum tier quantity must cover its minimum');
        }
    }
}
