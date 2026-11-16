<?php

namespace App\Libraries\Cement;

final class Permissions
{
    public function __construct(private Store $s)
    {
    }
    private function admin(array $actor): void
    {
        if (!in_array($actor['role'], ['cement_admin','cement_system_admin'])) {
            throw new Problem('Administrator access required', 403);
        }
    }
    public function read(array $actor): array
    {
        $this->admin($actor);
        $result = [];
        foreach ($this->s->catalog->roles as $role => $policy) {
            $rights = [];
            foreach ($this->s->catalog->types as $type => $spec) {
                $rights[$type] = ['title' => $spec['name'],'read' => $this->s->catalog->allowed($role, $type),'write' => $this->s->catalog->allowed($role, $type, true),'private' => $this->s->catalog->isPrivate($type),'computed' => in_array($type, ['inventory_stock','inventory_cost_layer','account_balance','report_result','report_delivery','cement_audit_event'])];
            }$result[$role] = ['title' => $policy['name'],'locked' => in_array($role, ['cement_admin','cement_system_admin']),'permissions' => $rights,'modules' => $policy['modules']];
        }
        return ['revision' => $this->s->catalog->permissionRevision,'roles' => $result,'modules' => $this->s->catalog->json('modules')];
    }
    public function update(array $actor, array $input): array
    {
        $this->admin($actor);
        $role = $input['role'] ?? '';
        if (!isset($this->s->catalog->roles[$role]) || in_array($role, ['cement_admin','cement_system_admin'])) {
            throw new Problem('Administrator roles retain their recovery privileges');
        }
        $rights = $input['permissions'] ?? null;
        if (!is_array($rights) || array_diff(array_keys($rights), array_keys($this->s->catalog->types))) {
            throw new Problem('Invalid permission map');
        }
        foreach ($rights as $type => $grant) {
            if (!is_array($grant) || !is_bool($grant['read'] ?? null) || !is_bool($grant['write'] ?? null) || ($grant['write'] && !$grant['read'])) {
                throw new Problem('Write permission requires read permission');
            }if ($this->s->catalog->isPrivate($type) && !in_array($role, ['cement_accountant']) && ($grant['read'] || $grant['write'])) {
                throw new Problem('This role cannot receive confidential payroll or financial data');
            }
        }
        $modules = $input['modules'] ?? $this->s->catalog->roles[$role]['modules'];
        $known = array_column($this->s->catalog->json('modules'), 'code');
        if (!is_array($modules) || !array_is_list($modules) || array_diff($modules, $known)) {
            throw new Problem('Invalid portal module selection');
        }
        $i = (int)$actor['institution_id'];
        $this->s->begin();
        try {
            $this->s->loadPermissions($i);
            if (($input['revision'] ?? null) !== $this->s->catalog->permissionRevision) {
                throw new Problem('Permissions changed; reload before editing', 409);
            }
            foreach ($rights as $type => $grant) {
                $this->s->run('INSERT INTO role_permissions(institution_id,role,type,can_read,can_write) VALUES (?,?,?,?,?) ON CONFLICT(institution_id,role,type) DO UPDATE SET can_read=excluded.can_read,can_write=excluded.can_write', [$i,$role,$type,(int)$grant['read'],(int)$grant['write']]);
            }
            $this->s->run('INSERT INTO role_modules(institution_id,role,modules) VALUES (?,?,?) ON CONFLICT(institution_id,role) DO UPDATE SET modules=excluded.modules', [$i,$role,json_encode(array_values(array_unique($modules)), JSON_THROW_ON_ERROR)]);
            $this->s->run('INSERT INTO audit(institution_id,actor_id,request_id,event,created_at) VALUES (?,?,?,?,?)', [$i,$actor['id'],'permissions-'.bin2hex(random_bytes(8)),json_encode(['operation' => 'permissions','role' => $role,'permissions' => $rights,'modules' => $modules], JSON_THROW_ON_ERROR),gmdate('c')]);
            $this->s->commit();
            $this->s->loadPermissions($i);
            return $this->read($actor);
        } catch (\Throwable $e) {
            $this->s->rollback();
            throw $e;
        }
    }
}
