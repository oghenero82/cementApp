<?php

namespace App\Controllers;

use App\Libraries\MetaForge\Auth\{AuthRepository,AuthService,RequestScope,DomainResolver};
use App\Libraries\MetaForge\Catalog\Metadata as M;
use App\Libraries\MetaForge\Mutation\MutationContext;
use App\Libraries\CementIntegration\NativeStatement;
use Config\Database;

final class CementQueries extends BaseController
{
    public function statement()
    {
        return $this->query(false);
    }
    public function report()
    {
        return $this->query(true);
    }
    private function query(bool $report)
    {
        $status = 200;
        try {
            $repo = new AuthRepository(Database::connect());
            (new RequestScope($repo, session()))->enter(DomainResolver::requestHostname($this->request));
            $auth = (new AuthService($repo, session()))->snapshot();
            $expected = $this->request->getHeaderLine('X-MetaForge-Context');
            if ($expected !== '' && !hash_equals($auth['contextRevision'], $expected)) {
                throw new \RuntimeException('Context changed');
            }if (M::code($repo->role($auth['roleId'])) === 'cement_driver') {
                throw new \RuntimeException('Driver queries unavailable');
            }$ctx = new MutationContext($auth, $repo->catalog());
            $result = $report ? \App\Libraries\CementIntegration\NativeTripProfit::generate($ctx, $this->request->getGet()) : NativeStatement::generate($ctx, $this->request->getGet());
        } catch (\Throwable $e) {
            $status = $e instanceof \InvalidArgumentException ? 422 : 403;
            $result = ['errors' => [['fieldPath' => $report ? 'report' : 'statement','message' => $status === 422 ? $e->getMessage() : ($report ? 'Report is not authorized' : 'Customer statement is not authorized')]]];
        }
        $result['csrf'] = ['header' => config('Security')->headerName,'hash' => service('security')->getHash()];
        return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode($status)->setJSON($result);
    }
}
