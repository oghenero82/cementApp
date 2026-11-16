<?php

namespace App\Libraries\Cement;

/** Institution-scoped SQL authority. Every business batch uses a single writer transaction. */
final class Store
{
    public \PDO $db;
    public Catalog $catalog;
    public int $mutationId = 0;
    public ?int $manualJournalId = null;
    public function __construct(?string $path = null)
    {
        $path ??= getenv('CEMENT_DB_PATH') ?: WRITEPATH . 'cement.sqlite';
        $this->db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000;');
        $this->catalog = new Catalog();
    }
    public function migrate(): void
    {
        $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS institutions (id INTEGER PRIMARY KEY, title TEXT NOT NULL, settings TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, institution_id INTEGER NOT NULL REFERENCES institutions(id), email TEXT NOT NULL UNIQUE, password TEXT NOT NULL, role TEXT NOT NULL, person_id INTEGER, display_name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS record_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE);
CREATE TABLE IF NOT EXISTS mutations (id INTEGER PRIMARY KEY AUTOINCREMENT, institution_id INTEGER NOT NULL, request_id TEXT NOT NULL, digest TEXT NOT NULL, response TEXT, created_at TEXT NOT NULL, UNIQUE(institution_id,request_id));
CREATE TABLE IF NOT EXISTS records (id INTEGER PRIMARY KEY AUTOINCREMENT, institution_id INTEGER NOT NULL REFERENCES institutions(id), type TEXT NOT NULL, business_code TEXT NOT NULL, data TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, deleted INTEGER NOT NULL DEFAULT 0, mutation_id INTEGER NOT NULL, updated_at TEXT NOT NULL, UNIQUE(institution_id,type,business_code));
CREATE INDEX IF NOT EXISTS records_sync ON records(institution_id,mutation_id,id);
CREATE INDEX IF NOT EXISTS records_type ON records(institution_id,type,deleted);
CREATE TABLE IF NOT EXISTS journals (id INTEGER PRIMARY KEY AUTOINCREMENT, institution_id INTEGER NOT NULL, source TEXT NOT NULL, record_id INTEGER, debit_account TEXT NOT NULL, credit_account TEXT NOT NULL, amount_minor INTEGER NOT NULL CHECK(amount_minor>0), created_at TEXT NOT NULL, UNIQUE(institution_id,source));
CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, institution_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, request_id TEXT NOT NULL, event TEXT NOT NULL, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS uploads (id INTEGER PRIMARY KEY AUTOINCREMENT, institution_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, period TEXT NOT NULL, filename TEXT NOT NULL, digest TEXT NOT NULL, source_file_digest TEXT, outcomes TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(institution_id,digest,period));
CREATE TABLE IF NOT EXISTS sale_recognitions (id INTEGER PRIMARY KEY AUTOINCREMENT, institution_id INTEGER NOT NULL, sale_id INTEGER NOT NULL, source TEXT NOT NULL, amount_minor INTEGER NOT NULL, tax_minor INTEGER NOT NULL, quantity REAL NOT NULL, created_at TEXT NOT NULL, UNIQUE(institution_id,source));
CREATE TABLE IF NOT EXISTS files (id TEXT PRIMARY KEY, institution_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, type TEXT NOT NULL, record_id INTEGER, filename TEXT NOT NULL, mime TEXT NOT NULL, extension TEXT NOT NULL, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS payment_allocations (institution_id INTEGER NOT NULL, payment_id INTEGER NOT NULL, sale_id INTEGER NOT NULL, amount_minor INTEGER NOT NULL CHECK(amount_minor>0), UNIQUE(institution_id,payment_id,sale_id));
CREATE TABLE IF NOT EXISTS role_permissions (institution_id INTEGER NOT NULL,role TEXT NOT NULL,type TEXT NOT NULL,can_read INTEGER NOT NULL,can_write INTEGER NOT NULL,PRIMARY KEY(institution_id,role,type));
CREATE TABLE IF NOT EXISTS role_modules (institution_id INTEGER NOT NULL,role TEXT NOT NULL,modules TEXT NOT NULL,PRIMARY KEY(institution_id,role));
CREATE TABLE IF NOT EXISTS login_attempts (identity TEXT PRIMARY KEY, failures INTEGER NOT NULL DEFAULT 0, attempted_at INTEGER NOT NULL);
SQL);
        if (!in_array('person_id', array_column($this->run('PRAGMA table_info(users)')->fetchAll(\PDO::FETCH_ASSOC), 'name'))) {
            $this->db->exec('ALTER TABLE users ADD COLUMN person_id INTEGER');
        }
        if (!in_array('record_id', array_column($this->run('PRAGMA table_info(journals)')->fetchAll(\PDO::FETCH_ASSOC), 'name'))) {
            $this->db->exec('ALTER TABLE journals ADD COLUMN record_id INTEGER');
        }
        if (!in_array('source_file_digest', array_column($this->run('PRAGMA table_info(uploads)')->fetchAll(\PDO::FETCH_ASSOC), 'name'))) {
            $this->db->exec('ALTER TABLE uploads ADD COLUMN source_file_digest TEXT');
        }
        foreach (array_keys($this->catalog->types) as $code) {
            $this->run('INSERT OR IGNORE INTO record_types(code) VALUES (?)', [$code]);
        }
    }
    public function loadPermissions(int $institution): void
    {
        $rows = $this->run('SELECT * FROM role_permissions WHERE institution_id=? ORDER BY role,type', [$institution])->fetchAll(\PDO::FETCH_ASSOC);
        $modules = $this->run('SELECT * FROM role_modules WHERE institution_id=? ORDER BY role', [$institution])->fetchAll(\PDO::FETCH_ASSOC);
        $this->catalog->permissionOverrides = [];
        foreach ($rows as $row) {
            $this->catalog->permissionOverrides[$row['role']][$row['type']] = ['read' => (bool)$row['can_read'],'write' => (bool)$row['can_write']];
        }
        $this->catalog->roles = $this->catalog->json('role_templates');
        foreach ($modules as $row) {
            $this->catalog->roles[$row['role']]['modules'] = json_decode($row['modules'], true, 512, JSON_THROW_ON_ERROR);
        }$this->catalog->permissionRevision = hash('sha256', json_encode([$rows,$modules], JSON_THROW_ON_ERROR));
    }
    public function run(string $sql, array $args = []): \PDOStatement
    {
        $s = $this->db->prepare($sql);
        $s->execute($args);
        return $s;
    }
    public function begin(): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
    }
    public function commit(): void
    {
        $this->db->exec('COMMIT');
    }
    public function rollback(): void
    {
        $this->db->exec('ROLLBACK');
    }
    public function all(int $institution, string $type): array
    {
        return array_map(fn ($r) => $this->hydrate($r), $this->run('SELECT * FROM records WHERE institution_id=? AND type=? AND deleted=0 ORDER BY id', [$institution,$type])->fetchAll(\PDO::FETCH_ASSOC));
    }
    public function get(int $institution, string $type, int $id): array
    {
        $r = $this->run('SELECT * FROM records WHERE institution_id=? AND type=? AND id=? AND deleted=0', [$institution,$type,$id])->fetch(\PDO::FETCH_ASSOC);
        if (!$r) {
            throw new Problem('Referenced record not found in this institution', 404);
        }
        return $this->hydrate($r);
    }
    public function hydrate(array $r): array
    {
        $r['data'] = json_decode($r['data'], true, 512, JSON_THROW_ON_ERROR);
        foreach (['id','version','mutation_id','deleted','institution_id'] as $key) {
            $r[$key] = (int)$r[$key];
        }
        return $r;
    }
    public function semantic(int $institution, string $type, int $id): array
    {
        return $this->catalog->decode($type, $this->get($institution, $type, $id)['data']);
    }
    public function save(int $institution, string $type, array $semantic, ?int $id = null, bool $deleted = false): array
    {
        $data = $this->catalog->encode($type, $semantic);
        $code = $semantic['CODE'] ?? strtoupper(substr($type, 0, 4)).'-'.bin2hex(random_bytes(6));
        if (isset($this->catalog->fields[$type]['CODE'])) {
            $data[$this->catalog->fields[$type]['CODE']] = $code;
        }
        $now = gmdate('c');
        if ($id) {
            $this->get($institution, $type, $id);
            $this->run('UPDATE records SET data=?,business_code=?,version=version+1,deleted=?,mutation_id=?,updated_at=? WHERE institution_id=? AND type=? AND id=?', [json_encode($data, JSON_THROW_ON_ERROR),$code,(int)$deleted,$this->mutationId,$now,$institution,$type,$id]);
        } else {
            $this->run('INSERT INTO records(institution_id,type,business_code,data,mutation_id,updated_at) VALUES (?,?,?,?,?,?)', [$institution,$type,$code,json_encode($data, JSON_THROW_ON_ERROR),$this->mutationId,$now]);
            $id = (int)$this->db->lastInsertId();
        }
        return ['id' => $id,'recordTypeCode' => $type,'data' => $data];
    }
    public function patch(int $institution, string $type, int $id, array $patch): array
    {
        return $this->save($institution, $type, array_replace($this->semantic($institution, $type, $id), $patch), $id);
    }
    public function related(int $institution, string $type, string $alias, int $id): array
    {
        $key = $this->catalog->fields[$type][$alias];
        return array_values(array_filter($this->all($institution, $type), fn ($r) => (int)($r['data'][$key] ?? 0) === $id));
    }
    public function journal(int $institution, string $source, string $debit, string $credit, float|int $amount, ?string $effectiveAt = null): void
    {
        if (!is_finite((float)$amount) || $amount < 0 || $amount > 1e12) {
            throw new Problem('Invalid posting amount');
        }
        $minor = (int)round($amount * 100);
        if (!$minor) {
            return;
        }
        if ($debit === $credit) {
            throw new Problem('Debit and credit accounts must differ');
        }
        $postedAt = $effectiveAt ?? ($this->manualJournalId ? $this->semantic($institution, 'journal', $this->manualJournalId)['POSTED_AT'] : gmdate('c'));
        if (strtotime($postedAt) > time()) {
            throw new Problem('Future-dated journals cannot be posted');
        }
        if (!str_starts_with($source, 'year_close:')) {
            foreach ($this->all($institution, 'fiscal_year') as $r) {
                $year = $this->catalog->decode('fiscal_year', $r['data']);
                $day = substr($postedAt, 0, 10);
                if ($year['STATUS'] === 'closed' && $day >= $year['START_DATE'] && $day <= $year['END_DATE']) {
                    throw new Problem('This accounting period is closed');
                }
            }
        }
        $this->run('INSERT INTO journals(institution_id,source,debit_account,credit_account,amount_minor,created_at) VALUES (?,?,?,?,?,?)', [$institution,$source,$debit,$credit,$minor,$postedAt]);
        (new SharedServices($this))->recordPosting($institution, (int)$this->db->lastInsertId(), $source, $debit, $credit, $minor);
    }
    public function settings(int $institution): array
    {
        return json_decode($this->run('SELECT settings FROM institutions WHERE id=?', [$institution])->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    }
    public function typeIds(): array
    {
        return $this->run('SELECT code,id FROM record_types')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
