<?php

namespace App\Controllers;

use App\Libraries\MetaForge\Auth\{AuthRepository,AuthService,RequestScope,DomainResolver};
use App\Libraries\MetaForge\Catalog\MetadataRevision;
use Config\Database;

final class CementCapabilities extends BaseController
{
    public function index()
    {
        $this->response->setHeader('Cache-Control', 'no-store');
        try {
            $db = Database::connect();
            $records = new AuthRepository($db);
            (new RequestScope($records, session()))->enter(DomainResolver::requestHostname($this->request));
            $auth = (new AuthService($records, session()))->snapshot();
            $expected = $this->request->getHeaderLine('X-MetaForge-Context');
            if ($expected !== '' && !hash_equals($auth['contextRevision'], $expected)) {
                throw new \RuntimeException('Context changed');
            }
            $permissions = [];
            foreach ($auth['permissions'] as $grant) {
                if (($grant['allowed'] ?? false) === true && isset($grant['rtid'],$grant['action']) && isset($auth['recordTypes'][$grant['rtid']])) {
                    $permissions[] = ['rtid' => $grant['rtid'],'action' => $grant['action'],'allowed' => true];
                }
            }
            return $this->response->setJSON(['permissions' => $permissions,'metadataRevision' => (new MetadataRevision($db))->current(),'contextRevision' => $auth['contextRevision']]);
        } catch (\Throwable $e) {
            log_message('error', 'Cement capabilities: '.$e->getMessage());
            return $this->response->setStatusCode(403)->setJSON(['message' => 'Native Cement capabilities are not authorized']);
        }
    }
}
