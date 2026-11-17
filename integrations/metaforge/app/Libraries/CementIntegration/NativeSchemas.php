<?php

namespace App\Libraries\CementIntegration;

/** Original seeder contracts plus the explicit reservation addon; shared finance types stay native. */
final class NativeSchemas
{
    public static function types(bool $reservations = true): array
    {
        $types = json_decode(file_get_contents(ROOTPATH.'resources/cement/recordtypes.json'), true, 64, JSON_THROW_ON_ERROR);
        if ($reservations) {
            foreach (json_decode(file_get_contents(ROOTPATH.'resources/cement/shared_recordtypes.json'), true, 64, JSON_THROW_ON_ERROR) as $type) {
                if ($type['code'] === 'inventory_reservation') {
                    $types[] = $type + ['indexes' => []];
                }
            }
        }return $types;
    }
    public static function maps(): array
    {
        return json_decode(file_get_contents(ROOTPATH.'resources/cement/semantic_field_registry.json'), true, 64, JSON_THROW_ON_ERROR) + array_intersect_key(json_decode(file_get_contents(ROOTPATH.'resources/cement/shared_semantic_field_registry.json'), true, 64, JSON_THROW_ON_ERROR), ['inventory_reservation' => true]);
    }
}
