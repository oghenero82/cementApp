<?php

namespace App\Libraries\Cement\Native;

use App\Libraries\Cement\Problem;

/** Native MetaForge cookies, CSRF and context are confined to the Cement server session. */
final class Transport
{
    public function __construct(private ?object $http = null, private ?object $session = null)
    {
    }
    private function vault(): object
    {
        return $this->session ?? session();
    }
    public function clear(): void
    {
        $this->vault()->remove('metaforge_native');
    }
    public function request(string $method, string $path, ?array $body = null): array
    {
        $base = rtrim(getenv('METAFORGE_BASE_URL') ?: '', '/');
        $url = parse_url($base);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new Problem('Configure an HTTPS MetaForge base URL without credentials or query parameters', 503);
        }
        if (!preg_match('#^(bootstrap|cement/capabilities|cement/reports(?:\?.*)?|cement/customer-statement(?:\?.*)?|cement/files(?:/[a-f0-9]{32})?|auth/(login|role|password|logout|reauthenticate)|sync(?:\?.*)?|records(?:\?.*)?|mutations)$#D', $path)) {
            throw new Problem('Unsupported native MetaForge endpoint', 501);
        }
        $state = $this->vault()->get('metaforge_native') ?? [];
        if (($state['origin'] ?? $base) !== $base) {
            $state = [];
            $this->clear();
        }
        $state['origin'] = $base;
        if ($method !== 'GET' && empty($state['csrf'])) {
            $this->request('GET', 'bootstrap');
            $state = $this->vault()->get('metaforge_native') ?? [];
        }
        $headers = ['Accept' => 'application/json'];
        if (!empty($state['cookies'])) {
            $headers['Cookie'] = implode('; ', array_map(fn ($k, $v) => $k.'='.$v, array_keys($state['cookies']), $state['cookies']));
        }
        if ($method !== 'GET') {
            $headers[$state['csrf']['header']] = $state['csrf']['hash'];
        }
        if (!empty($state['context'])) {
            $headers['X-MetaForge-Context'] = $state['context'];
        }
        $verify = getenv('METAFORGE_CA_BUNDLE') ?: true;
        $options = ['headers' => $headers, 'timeout' => 20, 'connect_timeout' => 5, 'http_errors' => false, 'allow_redirects' => false, 'verify' => $verify, 'shareOptions' => false];
        if ($body !== null) {
            $options['json'] = (object)$body;
        }
        try {
            $response = ($this->http ?? service('curlrequest', [], null, null, false))->request($method, $base.'/api/'.$path, $options);
        } catch (\Throwable) {
            throw new Problem('MetaForge is unavailable; this request is unconfirmed and was not posted locally', 503);
        }
        // Record security-token rotation even on rejected requests. Never retry a mutation automatically.
        $cookieHeader = $response->getHeader('Set-Cookie');
        $cookieLines = $cookieHeader ? $cookieHeader->getValue() : [];
        foreach (is_array($cookieLines) ? $cookieLines : [$cookieLines] as $line) {
            if (!is_string($line) || !preg_match('/^([!#$%&\x27*+.^_`|~a-zA-Z0-9-]+)=([^;\r\n]*)/', $line, $match)) {
                continue;
            }
            if (preg_match('/;\s*Max-Age=0(?:;|$)/i', $line) || $match[2] === '') {
                unset($state['cookies'][$match[1]]);
            } else {
                $state['cookies'][$match[1]] = $match[2];
            }
        }
        $this->vault()->set('metaforge_native', $state);
        try {
            $data = json_decode($response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new Problem('MetaForge returned unreadable JSON; this request is unconfirmed', 502);
        }
        if (!is_array($data)) {
            throw new Problem('Invalid native MetaForge response', 502);
        }
        $csrf = $data['csrf'] ?? null;
        if (is_array($csrf) && is_string($csrf['header'] ?? null) && preg_match('/^[A-Za-z0-9-]+$/D', $csrf['header']) && is_string($csrf['hash'] ?? null) && preg_match('/^[A-Za-z0-9]+$/D', $csrf['hash'])) {
            $state['csrf'] = $csrf;
        }
        if (is_string($data['context']['revision'] ?? null)) {
            $state['context'] = $data['context']['revision'];
        }
        if (is_string($data['contextRevision'] ?? null)) {
            $state['context'] = $data['contextRevision'];
        }
        $this->vault()->set('metaforge_native', $state);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            if ($status === 422 && array_filter($data['errors'] ?? [], fn ($e) => ($e['code'] ?? null) === 'conflict')) {
                $status = 409;
            }
            $fields = [];
            foreach ($data['errors'] ?? [] as $error) {
                if (is_string($error['fieldPath'] ?? null) && is_string($error['message'] ?? null)) {
                    $fields[$error['fieldPath']] = $error['message'];
                }
            }
            throw new Problem($fields ? implode('; ', array_unique($fields)) : 'MetaForge rejected this request', $status >= 300 && $status < 400 ? 502 : $status, $fields);
        }
        unset($data['csrf']);
        if ($path === 'auth/logout') {
            $this->clear();
        }
        return $data;
    }
}
