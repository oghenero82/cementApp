<?php

namespace App\Libraries\CementIntegration;

/** Identity bindings for trusted server-generated plans, inside the existing outer transaction. */
final class TrustedPlans
{
    private static array $origins = [];
    public static function origin(): ?string
    {
        return self::$origins ? self::$origins[array_key_last(self::$origins)] : null;
    }
    public static function apply(array &$response, callable $execute): void
    {
        $declared = [];
        foreach ($response['mutationPlans'] as $plan) {
            $ref = $plan['_cementClientRef'] ?? null;
            if ($ref !== null) {
                if (!is_string($ref) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $ref) || isset($declared[$ref])) {
                    throw new \InvalidArgumentException('Invalid or duplicate batch identity');
                }$declared[$ref] = true;
            }
        }
        $bindings = [];
        $results = [];
        foreach ($response['mutationPlans'] as $plan) {
            $ref = $plan['_cementClientRef'] ?? null;
            $references = $plan['_cementReferences'] ?? [];
            $recordRef = $plan['_cementRecordRef'] ?? null;
            unset($plan['_cementClientRef'],$plan['_cementReferences'],$plan['_cementRecordRef']);
            if ($recordRef !== null) {
                if (!isset($bindings[$recordRef])) {
                    throw new \InvalidArgumentException('Unknown native record binding');
                }$plan['recordId'] = $bindings[$recordRef];
            }
            foreach ($references as $field => $target) {
                if (!isset($bindings[$target])) {
                    throw new \InvalidArgumentException('Batch references must point to an earlier record');
                }$plan['data'][$field] = $bindings[$target];
            }
            $origin = $plan['_cementGeneratedBy'] ?? self::origin();
            unset($plan['_cementGeneratedBy']);
            self::$origins[] = $origin;
            try {
                $row = $execute($plan);
            } finally {
                array_pop(self::$origins);
            }
            if (!is_int($row['recordId'] ?? null) || $row['recordId'] <= 0) {
                throw new \RuntimeException('Native plan did not return its physical identity');
            }
            if ($ref !== null) {
                $bindings[$ref] = $row['recordId'];
            }
            $results[] = ['recordTypeId' => $row['recordTypeId'],'recordId' => $row['recordId']];
        }
        if ($bindings) {
            $response['results']['resultMap'] = $bindings;
            $response['results']['results'] = $results;
        }
    }
}
