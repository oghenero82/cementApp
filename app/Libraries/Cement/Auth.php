<?php

namespace App\Libraries\Cement;

final class Auth
{
    public function __construct(private Store $s)
    {
    }
    public static function hashPassword(mixed $password): string
    {
        if (!is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new Problem('Use at least 12 characters and at most 72 UTF-8 bytes for the password');
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }
    public function setup(array $input): array
    {
        $email = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        $title = trim($input['institution'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($title) < 2) {
            throw new Problem('Enter an institution, valid email and institution name');
        }
        $hash = self::hashPassword($password);
        $this->s->begin();
        try {
            if ($this->s->run('SELECT count(*) FROM institutions')->fetchColumn() > 0) {
                throw new Problem('First-run setup is already complete', 409);
            }
            $settings = array_map(fn ($f) => $f['default'], $this->s->catalog->json('institution_settings_extension'));
            $this->s->run('INSERT INTO institutions(title,settings) VALUES (?,?)', [$title,json_encode($settings, JSON_THROW_ON_ERROR)]);
            $i = (int)$this->s->db->lastInsertId();
            $this->s->run('INSERT INTO users(institution_id,email,password,role,display_name) VALUES (?,?,?,?,?)', [$i,$email,$hash,'cement_system_admin',trim($input['displayName'] ?? 'Administrator')]);
            $userId = (int)$this->s->db->lastInsertId();
            foreach ($this->s->catalog->json('report_templates') as $report) {
                $this->s->save($i, 'report', ['CODE' => $report['code'],'NAME' => $report['name'],'MODULE_CODE' => $report['module'],'GENERATION_MODE' => 'server_stored','SOURCE_RECORD_TYPES' => $report['sourceRecordTypes'],'OUTPUT_FORMATS' => ['csv','xlsx','pdf'],'IS_ACTIVE' => true,'STATUS' => 'active']);
            }
            $this->s->commit();
            return $this->user($userId);
        } catch (\Throwable $e) {
            $this->s->rollback();
            throw $e;
        }
    }
    public function user(int $id): array
    {
        $u = $this->s->run('SELECT id,institution_id,email,role,person_id,display_name FROM users WHERE id=? AND active=1', [$id])->fetch(\PDO::FETCH_ASSOC);
        if (!$u) {
            throw new Problem('Please sign in', 401);
        }return $u;
    }
    public function login(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        $identity = hash('sha256', $email.'|'.$ip);
        $attempt = $this->s->run('SELECT * FROM login_attempts WHERE identity=?', [$identity])->fetch(\PDO::FETCH_ASSOC);
        if ($attempt && $attempt['failures'] >= 8 && $attempt['attempted_at'] > time() - 900) {
            throw new Problem('Too many attempts; retry in 15 minutes', 429);
        }
        $u = $this->s->run('SELECT * FROM users WHERE email=? AND active=1', [$email])->fetch(\PDO::FETCH_ASSOC);
        if (!$u || !password_verify($password, $u['password'])) {
            $n = $attempt && $attempt['attempted_at'] > time() - 900 ? $attempt['failures'] + 1 : 1;
            $this->s->run('INSERT INTO login_attempts(identity,failures,attempted_at) VALUES (?,?,?) ON CONFLICT(identity) DO UPDATE SET failures=excluded.failures,attempted_at=excluded.attempted_at', [$identity,$n,time()]);
            throw new Problem('Email or password is incorrect', 401);
        }
        $this->s->run('DELETE FROM login_attempts WHERE identity=?', [$identity]);
        return $this->user((int)$u['id']);
    }
    public function createUser(array $actor, array $input): array
    {
        if (!in_array($actor['role'], ['cement_system_admin','cement_admin'])) {
            throw new Problem('Administrator access required', 403);
        }
        $role = $input['role'] ?? '';
        $email = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        if (!isset($this->s->catalog->roles[$role]) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            throw new Problem('A valid role, email and password of at least 12 characters are required');
        }
        $personId = !empty($input['personId']) ? (int)$input['personId'] : null;
        if ($personId) {
            $this->s->get((int)$actor['institution_id'], 'person', $personId);
        }
        $this->s->run('INSERT INTO users(institution_id,email,password,role,person_id,display_name) VALUES (?,?,?,?,?,?)', [$actor['institution_id'],$email,self::hashPassword($password),$role,$personId,$input['displayName'] ?? $email]);
        return $this->user((int)$this->s->db->lastInsertId());
    }
}
