<?php

namespace App\Libraries\Cement;

final class Scope
{
    public function __construct(private Store $s)
    {
    }
    public function key(array $actor): string
    {
        $key = $this->s->catalog->permissionRevision.':'.$actor['institution_id'].':'.$actor['id'].':'.$actor['role'].':'.($actor['person_id'] ?? 0);
        $reports = [];
        foreach ($this->s->all((int)$actor['institution_id'], 'report') as $r) {
            $d = $this->s->catalog->decode('report', $r['data']);
            if ($this->allows($actor, 'report', $d)) {
                $reports[] = $r['id'];
            }
        }$key .= ':'.hash('sha256', json_encode($reports));
        if ($actor['role'] === 'cement_driver') {
            $assigned = [];
            foreach ($this->s->all((int)$actor['institution_id'], 'trip') as $r) {
                $d = $this->s->catalog->decode('trip', $r['data']);
                if (($d['DRIVER_ID'] ?? 0) === (int)($actor['person_id'] ?? 0)) {
                    $assigned[] = $r['id'];
                }
            }
            $key .= ':'.hash('sha256', json_encode($assigned));
        }return $key;
    }
    public function allows(array $actor, string $type, array $data): bool
    {
        if (in_array($type, ['report','report_result','report_schedule','report_delivery'])) {
            try {
                $definition = $data;
                $snapshot = null;
                $i = (int)$actor['institution_id'];
                if ($type === 'report_delivery') {
                    $result = $this->s->semantic($i, 'report_result', $data['REPORT_RESULT_ID']);
                    $snapshot = $result;
                    $definition = $this->s->semantic($i, 'report', $result['REPORT_ID']);
                } elseif ($type !== 'report') {
                    $snapshot = $type === 'report_result' ? $data : null;
                    $definition = $this->s->semantic($i, 'report', $data['REPORT_ID']);
                }
                if ($snapshot) {
                    $sources = $snapshot['PARAMETERS']['authorizationSources'] ?? null;
                    $roles = $snapshot['PARAMETERS']['authorizationRoles'] ?? [];
                    if ($sources === null) {
                        foreach ($this->s->catalog->json('report_templates') as $template) {
                            if ($template['code'] === ($definition['CODE'] ?? null)) {
                                $sources = $template['sourceRecordTypes'];
                            }
                        }
                    }
                    if (!is_array($sources)) {
                        return false;
                    }foreach ($sources as $source) {
                        if (!$this->s->catalog->allowed($actor['role'], $source)) {
                            return false;
                        }
                    }if ($roles && !in_array($actor['role'], $roles)) {
                        return false;
                    }
                }
                if (!empty($definition['ACCESS_ROLES']) && !in_array($actor['role'], $definition['ACCESS_ROLES'])) {
                    return false;
                }
                foreach ($definition['SOURCE_RECORD_TYPES'] ?? [] as $target) {
                    if (!$this->s->catalog->allowed($actor['role'], $target)) {
                        return false;
                    }
                }
                return true;
            } catch (Problem) {
                return false;
            }
        }
        if ($actor['role'] !== 'cement_driver') {
            return true;
        }$person = (int)($actor['person_id'] ?? 0);
        if (!$person) {
            return false;
        }$i = (int)$actor['institution_id'];
        try {
            if ($type === 'trip') {
                return (int)($data['DRIVER_ID'] ?? 0) === $person;
            }
            if ($type === 'trip_destination' || $type === 'fuel_transaction') {
                return !empty($data['TRIP_ID']) && $this->allows($actor, 'trip', $this->s->semantic($i, 'trip', $data['TRIP_ID']));
            }
            if ($type === 'delivery_proof') {
                return !empty($data['TRIP_DESTINATION_ID']) && $this->allows($actor, 'trip_destination', $this->s->semantic($i, 'trip_destination', $data['TRIP_DESTINATION_ID']));
            }
            if ($type === 'vehicle') {
                foreach ($this->s->all($i, 'trip') as $row) {
                    $trip = $this->s->catalog->decode('trip', $row['data']);
                    if (($trip['VEHICLE_ID'] ?? null) === ($data['_ID'] ?? null) && ($trip['DRIVER_ID'] ?? 0) === $person) {
                        return true;
                    }
                }
            }
        } catch (Problem) {
            return false;
        }return false;
    }
}
