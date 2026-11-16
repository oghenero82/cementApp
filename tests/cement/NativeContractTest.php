<?php

use App\Libraries\Cement\{Catalog,Problem};
use App\Libraries\Cement\Native\{Transport,Metadata,Backend};
use PHPUnit\Framework\TestCase;

final class NativeContractTest extends TestCase
{
    protected function setUp(): void { putenv('METAFORGE_BASE_URL=https://native.example.test'); }
    protected function tearDown(): void { putenv('METAFORGE_BASE_URL');putenv('METAFORGE_CA_BUNDLE'); }
    private function vault(): object { return new class {
        public array $data=[];
        public function get($key){return $this->data[$key]??null;}
        public function set($key,$value){$this->data[$key]=$value;}
        public function remove($key){unset($this->data[$key]);}
    }; }
    private function http(array $responses): object { return new class($responses) {
        public array $requests=[];
        public function __construct(private array $responses){}
        public function request($method,$url,$options){$this->requests[]=[$method,$url,$options];$spec=array_shift($this->responses);if(!$spec)throw new RuntimeException('Unexpected request');return new class($spec){
            public function __construct(private array $spec){}
            public function getBody(){return isset($this->spec['raw'])?$this->spec['raw']:json_encode($this->spec['body']);}
            public function getStatusCode(){return $this->spec['status']??200;}
            public function getHeader($name){if($name!=='Set-Cookie'||empty($this->spec['cookies']))return null;return new class($this->spec['cookies']){public function __construct(private array $values){}public function getValue(){return $this->values;}};}
        };}
    }; }
    private function metadataFixture(): array {
        $c=new Catalog();$fields=[];
        foreach($c->descriptors('supplier') as $field)$fields[]=['code'=>$c->fields['supplier'][$field['alias']],'type'=>$field['type']==='reference'?'integer':$field['type'],'properties'=>['required'=>$field['required'],'persistent'=>true]];
        return ['authenticated'=>true,'institutionId'=>18,'roleId'=>26,'metadataRevision'=>7,'context'=>['revision'=>'ctx-7'],'role'=>['recordCode'=>'developer_role','jsonData'=>[]],'portal'=>['recordCode'=>'developer_portal'],'user'=>['recordId'=>31,'jsonData'=>['email'=>'test@example.test']],
            'recordTypes'=>[['recordId'=>501,'recordTypeId'=>1,'recordCode'=>'supplier','jsonData'=>['name'=>'Supplier','fields'=>$fields,'settings'=>['cementFieldAliases'=>$c->fields['supplier'],'cementOperations'=>['read','insert','update','delete']]]]],'cementPermissions'=>[['rtid'=>501,'action'=>'insert','allowed'=>true],['rtid'=>501,'action'=>'read','allowed'=>true]]];
    }
    public function testNativeCookiesAndRotatingCsrfStayInServerVaultAndTlsIsVerified(): void {
        $v=$this->vault();$h=$this->http([
            ['body'=>['csrf'=>['header'=>'X-CSRF-TOKEN','hash'=>'abc123']],'cookies'=>['mf_session=opaque; HttpOnly; Secure; SameSite=Lax']],
            ['body'=>['authenticated'=>true,'csrf'=>['header'=>'X-CSRF-TOKEN','hash'=>'def456'],'context'=>['revision'=>'ctx']]],
            ['body'=>['authenticated'=>false,'csrf'=>['header'=>'X-CSRF-TOKEN','hash'=>'ghi789']]],
        ]);$t=new Transport($h,$v);$login=$t->request('POST','auth/login',['email'=>'test@example.test','password'=>'test-input']);
        self::assertArrayNotHasKey('csrf',$login);self::assertSame('mf_session=opaque',$h->requests[1][2]['headers']['Cookie']);self::assertSame('abc123',$h->requests[1][2]['headers']['X-CSRF-TOKEN']);
        self::assertTrue($h->requests[1][2]['verify']);self::assertFalse($h->requests[1][2]['allow_redirects']);self::assertArrayNotHasKey('Authorization',$h->requests[1][2]['headers']);
        $t->request('POST','auth/logout',[]);self::assertSame('def456',$h->requests[2][2]['headers']['X-CSRF-TOKEN']);self::assertIsObject($h->requests[2][2]['json']);self::assertArrayNotHasKey('metaforge_native',$v->data);
    }
    public function testNativeRejectionRotatesTokenWithoutAutomaticallyReplayingMutation(): void {
        $v=$this->vault();$v->set('metaforge_native',['csrf'=>['header'=>'X-CSRF-TOKEN','hash'=>'abc123']]);$h=$this->http([['status'=>422,'body'=>['csrf'=>['header'=>'X-CSRF-TOKEN','hash'=>'def456'],'errors'=>[['fieldPath'=>'quantity','message'=>'Invalid quantity']]]]]);
        try{(new Transport($h,$v))->request('POST','mutations',['requestId'=>'same-id']);self::fail('Rejection accepted');}catch(Problem $e){self::assertSame(422,$e->httpStatus);self::assertSame(['quantity'=>'Invalid quantity'],$e->fields);}
        self::assertCount(1,$h->requests);self::assertSame('def456',$v->get('metaforge_native')['csrf']['hash']);
    }
    public function testNativeHttpAndCredentialUrlsAreRejectedBeforeTransport(): void {
        foreach(['http://native.example.test','https://user:password@native.example.test'] as $base){putenv('METAFORGE_BASE_URL='.$base);$h=$this->http([]);try{(new Transport($h,$this->vault()))->request('GET','bootstrap');self::fail('Unsafe URL accepted');}catch(Problem $e){self::assertSame(503,$e->httpStatus);}self::assertSame([],$h->requests);}
    }
    public function testMetadataUsesExplicitAliasesAndOpaqueDynamicIds(): void {
        $b=$this->metadataFixture();$b['recordTypes'][0]['jsonData']['settings']['cementFieldAliases']['NAME']='supplierTitle';foreach($b['recordTypes'][0]['jsonData']['fields'] as &$field)if($field['code']==='name')$field['code']='supplierTitle';unset($field);
        $m=(new Metadata())->build($b);self::assertSame('supplierTitle',$m['fieldsByRecordType']['supplier']['NAME']);self::assertSame(501,$m['recordTypeIds']['supplier']);self::assertTrue($m['descriptors']['supplier']['canWrite']);
        $rows=(new Metadata())->rows(['12'=>[['recordId'=>9,'recordTypeId'=>501,'mutationId'=>8,'isDeleted'=>0,'jsonData'=>['code'=>'S','supplierTitle'=>'Test','status'=>'active','settings'=>[]]]]],$m);
        self::assertSame(8,$rows[0]['version']);self::assertSame('12',$rows[0]['physicalTableId']);self::assertSame('Test',$rows[0]['data']['supplierTitle']);self::assertArrayNotHasKey('settings',$rows[0]['data']);
        unset($b['recordTypes'][0]['jsonData']['settings']['cementFieldAliases']['NAME']);$this->expectException(Problem::class);(new Metadata())->build($b);
    }
    public function testNativeMutationUsesRegisteredServiceAndExactRequestIdentity(): void {
        $b=$this->metadataFixture();$m=(new Metadata())->build($b);$csrf=['header'=>'X-CSRF-TOKEN','hash'=>'abc123'];$h=$this->http([
            ['body'=>$b+['csrf'=>$csrf]],['body'=>['permissions'=>$b['cementPermissions'],'metadataRevision'=>7,'contextRevision'=>'ctx-7']],
            ['body'=>['success'=>true,'mutationId'=>9,'results'=>['cement.execute'=>['resultMap'=>[]]],'records'=>[['physicalTableId'=>12,'record'=>['recordId'=>22,'recordTypeId'=>501,'mutationId'=>9,'isDeleted'=>0,'jsonData'=>['code'=>'S','name'=>'Test','status'=>'active']]]],'csrf'=>$csrf]],
        ]);$envelope=['requestId'=>'b3351717-78bd-4ab3-9a4d-4b05bdebd993','registryRevision'=>$m['revision'],'recordTypeCode'=>'supplier','recordTypeId'=>501,'operation'=>'insert','data'=>['code'=>'S','name'=>'Test','status'=>'active']];
        $result=(new Backend(new Transport($h,$this->vault())))->request('POST','mutation',$envelope);
        self::assertSame('confirmed',$result['state']);self::assertSame(22,$result['changes'][0]['recordId']);$sent=(array)$h->requests[2][2]['json'];self::assertSame('service_only_mode',$sent['mode']);self::assertSame($envelope,$sent['service']['parameters']['envelope']);self::assertSame($envelope['requestId'],$sent['requestId']);
    }
}
