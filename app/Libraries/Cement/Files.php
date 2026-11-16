<?php

namespace App\Libraries\Cement;

final class Files
{
    public function __construct(private Store $s)
    {
    }
    private static function directory(): string
    {
        return getenv('CEMENT_ATTACHMENTS_PATH') ?: WRITEPATH . 'attachments';
    }
    public static function filePath(array $file): string
    {
        return self::directory() . '/' . $file['id'] . '.' . $file['extension'];
    }
    public function upload(array $actor, \CodeIgniter\HTTP\IncomingRequest $request): array
    {
        $type = $request->getPost('recordTypeCode');
        if (!is_string($type) || !$this->s->catalog->allowed($actor['role'], $type, true)) {
            throw new Problem('Not permitted to upload attachments for this RecordType', 403);
        }
        $file = $request->getFile('attachment');
        if (!$file || !$file->isValid() || $file->getSize() > 5 * 1024 * 1024) {
            throw new Problem('Choose a valid attachment no larger than 5 MB');
        }
        $mime = $file->getMimeType();
        $ext = ['image/png' => 'png','image/jpeg' => 'jpg','image/webp' => 'webp','application/pdf' => 'pdf'][$mime] ?? null;
        if (!$ext) {
            throw new Problem('Attachments must be PNG, JPEG, WebP or PDF');
        }
        $id = bin2hex(random_bytes(16));
        $folder = self::directory();
        if (!is_dir($folder)) {
            mkdir($folder, 0700, true);
        }$file->move($folder, $id.'.'.$ext);
        $original = basename($file->getClientName());
        $this->s->run('INSERT INTO files(id,institution_id,actor_id,type,filename,mime,extension,created_at) VALUES (?,?,?,?,?,?,?,?)', [$id,$actor['institution_id'],$actor['id'],$type,$original,$mime,$ext,gmdate('c')]);
        return ['reference' => ['attachmentId' => $id,'filename' => $original,'mime' => $mime],'downloadPath' => '/api/files/'.$id];
    }
    public function authorize(array $actor, string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new Problem('Attachment not found', 404);
        }
        $f = $this->s->run('SELECT * FROM files WHERE id=? AND institution_id=?', [$id,$actor['institution_id']])->fetch(\PDO::FETCH_ASSOC);
        if (!$f || !$this->s->catalog->allowed($actor['role'], $f['type'])) {
            throw new Problem('Attachment not found', 404);
        }
        if ($f['record_id'] && !(new Scope($this->s))->allows($actor, $f['type'], $this->s->semantic((int)$actor['institution_id'], $f['type'], (int)$f['record_id']))) {
            throw new Problem('Attachment is not in your authorized scope', 403);
        }
        if ((int)$f['actor_id'] !== (int)$actor['id'] && !$f['record_id']) {
            throw new Problem('Attachment has not been shared through a record', 403);
        }
        return $f;
    }
    public function bind(array $actor, string $type, int $record, array $semantic): void
    {
        foreach (['SIGNATURE','PHOTOS','PHOTO_EVIDENCE','SUPPLIER_DOCUMENT'] as $alias) {
            if (!isset($semantic[$alias])) {
                continue;
            }$refs = in_array($alias, ['PHOTOS','PHOTO_EVIDENCE']) ? $semantic[$alias] : [$semantic[$alias]];
            foreach ($refs as $ref) {
                if (!is_array($ref) || empty($ref['attachmentId'])) {
                    throw new Problem('Use the attachment uploader for document references');
                }$f = $this->authorize($actor, $ref['attachmentId']);
                if ($f['type'] !== $type || ($f['record_id'] && (int)$f['record_id'] !== $record)) {
                    throw new Problem('Attachment is bound to another record');
                }
                $this->s->run('UPDATE files SET record_id=? WHERE id=?', [$record,$f['id']]);
            }
        }
    }
}
