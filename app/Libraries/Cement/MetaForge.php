<?php

namespace App\Libraries\Cement;

/** Explicit proxy mode; remote failure never invokes the local mutation engine. */
final class MetaForge
{
    public function __construct(private ?object $http = null, private ?object $sessions = null)
    {
    }
    private function vault(): object
    {
        return $this->sessions ?? session();
    }
    private function send(string $method, string $path, array $options = []): object
    {
        $base = rtrim(getenv('METAFORGE_BASE_URL') ?: '', '/');
        if (!str_starts_with($base, 'https://')) {
            throw new Problem('MetaForge requires a configured HTTPS base URL', 503);
        }
        $headers = ['Accept' => 'application/json'];
        $token = $this->vault()->get('metaforge_token');
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        try {
            return ($this->http ?? service('curlrequest'))->request($method, $base.'/'.trim(getenv('METAFORGE_API_PREFIX') ?: 'api', '/').'/'.$path, array_replace(['headers' => $headers,'timeout' => 20,'connect_timeout' => 5,'http_errors' => false,'allow_redirects' => false,'verify' => true], $options));
        } catch (\Throwable) {
            throw new Problem('MetaForge is unavailable. The authoritative backend remains MetaForge; pending requests were not posted locally.', 503);
        }
    }
    public function request(string $method, string $path, ?array $body = null): array
    {
        $allowed = ['auth/session','auth/login','auth/logout','meta/field-registry','portal/navigation','mutation','sync','dashboard','lookups','reports','reports/result','imports','health','users','settings','ledger','audit','auth/password'];
        if (!in_array(explode('?', $path)[0], $allowed)) {
            throw new Problem('Unsupported MetaForge contract endpoint', 501);
        }
        return $this->decode($this->send($method, $path, $body === null ? [] : ['json' => $body]), explode('?', $path)[0]);
    }
    private function decode(object $response, string $path): array
    {
        try {
            $data = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new Problem('MetaForge returned an invalid JSON contract', 502);
        }
        if (!is_array($data)) {
            throw new Problem('MetaForge returned an invalid response', 502);
        }
        if ($response->getStatusCode() >= 400) {
            throw new Problem($data['message'] ?? 'MetaForge rejected the request', $response->getStatusCode());
        }
        if ($path === 'auth/login') {
            if (!is_string($data['accessToken'] ?? null) || !isset($data['user'])) {
                throw new Problem('MetaForge login contract requires user and accessToken', 502);
            }
            $this->vault()->set('metaforge_token', $data['accessToken']);
            unset($data['accessToken']);
        }
        if ($path === 'meta/field-registry') {
            $data = (new Catalog())->validateMetadata($data);
        }
        unset($data['accessToken'],$data['refreshToken']);
        if ($path === 'auth/logout') {
            $this->vault()->remove('metaforge_token');
        }return $data;
    }
    public function upload(\CodeIgniter\HTTP\IncomingRequest $request, string $path = 'files/upload'): array
    {
        $file = $request->getFile('attachment');
        if (!$file || !$file->isValid() || $file->getSize() > 5 * 1024 * 1024) {
            throw new Problem('Choose a valid attachment no larger than 5 MB');
        }
        $type = $request->getPost('recordTypeCode');
        if (!is_string($type)) {
            throw new Problem('RecordType is required');
        }
        if (!in_array($path, ['files/upload','imports/preview'])) {
            throw new Problem('Unsupported upload contract');
        }
        return $this->decode($this->send('POST', $path, ['multipart' => ['recordTypeCode' => $type,'attachment' => new \CURLFile($file->getTempName(), $file->getMimeType(), basename($file->getClientName()))]]), $path);
    }
    public function download(string $id): object
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id)) {
            throw new Problem('Invalid attachment ID');
        }
        $response = $this->send('GET', 'files/'.$id);
        if ($response->getStatusCode() >= 400) {
            throw new Problem('MetaForge attachment is unavailable', $response->getStatusCode());
        }return $response;
    }
}
