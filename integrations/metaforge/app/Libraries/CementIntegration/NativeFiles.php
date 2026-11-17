<?php

namespace App\Libraries\CementIntegration;

use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};

/** Immutable native attachment blobs and manifests; authorization comes from native records. */
final class NativeFiles
{
    private static function folder(): string
    {
        $dir = WRITEPATH.'cement-attachments';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Attachment storage is unavailable');
        }return $dir;
    }
    public static function upload(MutationContext $context, string $type, string $encoded, string $filename): array
    {
        $r = new NativeRecords($context);
        $r->meta($type, 'insert');
        $aliases = $r->aliases($type);
        if (!array_intersect(['SIGNATURE','PHOTOS','PHOTO_EVIDENCE','SUPPLIER_DOCUMENT'], array_keys($aliases))) {
            throw new \InvalidArgumentException('This record type does not accept attachments');
        }
        if (strlen($encoded) > 6990508) {
            throw new \InvalidArgumentException('Attachment exceeds 5 MB');
        }$data = base64_decode($encoded, true);
        if ($data === false || strlen($data) === 0 || strlen($data) > 5242880) {
            throw new \InvalidArgumentException('Invalid attachment');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
        $ext = ['image/png' => 'png','image/jpeg' => 'jpg','image/webp' => 'webp','application/pdf' => 'pdf'][$mime] ?? null;
        if (!$ext) {
            throw new \InvalidArgumentException('Use PNG, JPEG, WebP or PDF');
        }
        if (str_starts_with($mime, 'image/') && getimagesizefromstring($data) === false) {
            throw new \InvalidArgumentException('Invalid image');
        }
        $id = bin2hex(random_bytes(16));
        $dir = self::folder();
        $name = mb_substr(basename(str_replace('\\', '/', $filename)), 0, 180);
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?: 'attachment.'.$ext;
        $manifest = ['id' => $id,'institutionId' => $context->auth['institutionId'],'actorId' => M::id($context->auth['user']),'recordTypeCode' => $type,'filename' => $name,'mime' => $mime,'extension' => $ext,'sha256' => hash('sha256', $data),'size' => strlen($data),'createdAt' => gmdate('c')];
        $blob = $dir.'/'.$id.'.'.$ext;
        $meta = $dir.'/'.$id.'.json';
        try {
            if (file_put_contents($blob, $data, LOCK_EX) !== strlen($data)) {
                throw new \RuntimeException('Attachment could not be saved');
            }chmod($blob, 0600);
            $json = json_encode($manifest, JSON_THROW_ON_ERROR);
            if (file_put_contents($meta.'.tmp', $json, LOCK_EX) !== strlen($json) || !rename($meta.'.tmp', $meta)) {
                throw new \RuntimeException('Attachment manifest could not be saved');
            }chmod($meta, 0600);
        } catch (\Throwable $e) {
            @unlink($blob);
            @unlink($meta.'.tmp');
            @unlink($meta);
            throw $e;
        }
        return ['reference' => ['attachmentId' => $id,'filename' => $name,'mime' => $mime],'downloadPath' => '/api/files/'.$id];
    }
    private static function contains(mixed $value, string $id): bool
    {
        if (!is_array($value)) {
            return false;
        }if (($value['attachmentId'] ?? null) === $id) {
            return true;
        }foreach ($value as $v) {
            if (is_array($v) && self::contains($v, $id)) {
                return true;
            }
        }return false;
    }
    public static function authorize(MutationContext $context, string $id, ?string $type = null): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid attachment identity');
        }$path = self::folder().'/'.$id.'.json';
        if (!is_file($path)) {
            throw ValidationException::field('attachment', 'Attachment is unavailable');
        }$m = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if ($m['institutionId'] !== $context->auth['institutionId'] || ($type !== null && $type !== $m['recordTypeCode'])) {
            throw ValidationException::field('attachment', 'Attachment is outside the record scope');
        }$r = new NativeRecords($context);
        $r->meta($m['recordTypeCode']);
        if ($m['actorId'] !== M::id($context->auth['user'])) {
            $shared = false;
            foreach ($r->rows($m['recordTypeCode']) as $row) {
                if (self::contains($row, $id)) {
                    $shared = true;
                    break;
                }
            }if (!$shared) {
                throw ValidationException::field('attachment', 'Unshared attachment belongs to another user');
            }
        }
        $blob = self::folder().'/'.$id.'.'.$m['extension'];
        if (!is_file($blob) || !hash_equals($m['sha256'], hash_file('sha256', $blob))) {
            throw ValidationException::field('attachment', 'Attachment integrity check failed');
        }return $m + ['path' => $blob];
    }
    public static function validate(MutationContext $context, string $type, array $data): void
    {
        foreach (['SIGNATURE','PHOTOS','PHOTO_EVIDENCE','SUPPLIER_DOCUMENT'] as $a) {
            if (!isset($data[$a])) {
                continue;
            }$refs = in_array($a, ['PHOTOS','PHOTO_EVIDENCE'], true) ? $data[$a] : [$data[$a]];
            if (!is_array($refs) || count($refs) > 20) {
                throw ValidationException::field('attachment', 'Too many attachments');
            }foreach ($refs as $ref) {
                if (!is_array($ref) || !is_string($ref['attachmentId'] ?? null)) {
                    throw ValidationException::field('attachment', 'Use an uploaded native attachment');
                }$file = self::authorize($context, $ref['attachmentId'], $type);
                if ($a === 'SIGNATURE' && !str_starts_with($file['mime'], 'image/')) {
                    throw ValidationException::field('signature', 'Use an uploaded signature image');
                }
            }
        }
    }
}
