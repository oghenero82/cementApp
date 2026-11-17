<?php

namespace App\Controllers;

use App\Libraries\MetaForge\Auth\{AuthRepository,AuthService,RequestScope,DomainResolver};
use App\Libraries\MetaForge\Mutation\{MutationContext,ValidationException};
use App\Libraries\CementIntegration\NativeFiles;
use Config\Database;

final class CementFiles extends BaseController
{
    public function handle(?string $id = null)
    {
        $status = 200;
        try {
            $repo = new AuthRepository(Database::connect());
            (new RequestScope($repo, session()))->enter(DomainResolver::requestHostname($this->request));
            $auth = (new AuthService($repo, session()))->snapshot();
            $expected = $this->request->getHeaderLine('X-MetaForge-Context');
            if ($expected !== '' && !hash_equals($auth['contextRevision'], $expected)) {
                throw new \RuntimeException('Context changed');
            }
            // Driver access stays disabled until native row assignment scopes are installed.
            if (\App\Libraries\MetaForge\Catalog\Metadata::code($repo->role($auth['roleId'])) === 'cement_driver') {
                throw new \RuntimeException('Driver attachment scoping unavailable');
            }
            $ctx = new MutationContext($auth, $repo->catalog());
            if ($id !== null) {
                $file = NativeFiles::authorize($ctx, $id);
                $result = ['content' => base64_encode(file_get_contents($file['path'])),'mime' => $file['mime'],'filename' => $file['filename']];
            } else {
                if (strlen($this->request->getBody()) > 7100000) {
                    throw new \InvalidArgumentException('Attachment request is too large');
                }$data = $this->request->getJSON(true);
                foreach (['recordTypeCode','content','filename'] as $key) {
                    if (!is_string($data[$key] ?? null)) {
                        throw new \InvalidArgumentException('Missing attachment field');
                    }
                }$result = NativeFiles::upload($ctx, $data['recordTypeCode'], $data['content'], $data['filename']);
            }
        } catch (\Throwable $e) {
            $status = $e instanceof \InvalidArgumentException ? 422 : 403;
            $result = ['errors' => [['fieldPath' => 'attachment','message' => $status === 422 ? $e->getMessage() : 'Attachment request denied']]];
        }
        $result['csrf'] = ['header' => config('Security')->headerName,'hash' => service('security')->getHash()];
        return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode($status)->setJSON($result);
    }
}
