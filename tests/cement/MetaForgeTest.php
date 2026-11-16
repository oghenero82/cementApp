<?php

use PHPUnit\Framework\TestCase;
use App\Libraries\Cement\{MetaForge,Catalog,Problem};

final class MetaForgeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('METAFORGE_BASE_URL');
    }
    public function testLoginKeepsTokenOnServerAndProxiesExactMutationEnvelope(): void
    {
        putenv('METAFORGE_BASE_URL=https://metaforge.example.test');
        $vault = new class () {
            public array $data = [];
            public function get($k)
            {
                return $this->data[$k] ?? null;
            }public function set($k, $v)
            {
                $this->data[$k] = $v;
            }public function remove($k)
            {
                unset($this->data[$k]);
            }
        };
        $http = new class () {
            public array $requests = [];
            public function request($method, $url, $options)
            {
                $this->requests[] = [$method,$url,$options];
                $body = str_ends_with($url, 'auth/login') ? ['user' => ['id' => 1],'accessToken' => 'test-token-not-a-credential'] : ['state' => 'confirmed','mutationId' => 3,'changes' => []];
                return new class ($body) {
                    public function __construct(private $body)
                    {
                    }public function getBody()
                    {
                        return json_encode($this->body);
                    }public function getStatusCode()
                    {
                        return 200;
                    }
                };
            }
        };
        $proxy = new MetaForge($http, $vault);
        $login = $proxy->request('POST', 'auth/login', ['email' => 'example@example.test','password' => 'test-input']);
        self::assertArrayNotHasKey('accessToken', $login);
        $body = ['requestId' => 'same-request-123','recordTypeId' => 98,'registryRevision' => 'mf:2','operation' => 'insert','data' => ['supplierAccessRef' => 'PIN']];
        $proxy->request('POST', 'mutation', $body);
        self::assertSame($body, $http->requests[1][2]['json']);
        self::assertSame('Bearer test-token-not-a-credential', $http->requests[1][2]['headers']['Authorization']);
        self::assertTrue($http->requests[1][2]['verify']);
        self::assertFalse($http->requests[1][2]['allow_redirects']);
    }
    public function testRemoteOutageReturns503AndNeverCallsLocalPosting(): void
    {
        putenv('METAFORGE_BASE_URL=https://metaforge.example.test');
        $vault = new class () {
            public function get($k)
            {
                return null;
            }
        };
        $http = new class () {
            public function request(...$args)
            {
                throw new RuntimeException('connection unavailable');
            }
        };
        try {
            (new MetaForge($http, $vault))->request('POST', 'mutation', ['requestId' => 'request-123']);
            self::fail('Outage was accepted');
        } catch (Problem $e) {
            self::assertSame(503, $e->httpStatus);
            self::assertStringContainsString('not posted locally', $e->getMessage());
        }
    }
    public function testHttpAndUnknownContractEndpointsAreRejected(): void
    {
        putenv('METAFORGE_BASE_URL=http://untrusted.example.test');
        $this->expectException(Problem::class);
        (new MetaForge())->request('GET', 'auth/session');
    }
}
