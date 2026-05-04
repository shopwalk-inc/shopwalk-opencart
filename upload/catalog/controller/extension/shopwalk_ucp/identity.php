<?php
/**
 * Catalog controller for UCP identity linking.
 *
 *   POST   /ucp/v1/identity/link                → link()
 *   DELETE /ucp/v1/identity/link/{link_id}      → unlink()
 */

namespace Opencart\Catalog\Controller\Extension\ShopwalkUcp;

require_once DIR_SYSTEM . 'library/shopwalk_opencart/bootstrap.php';

class Identity extends \Opencart\System\Engine\Controller
{
    public function link(): void
    {
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $identity = new \Shopwalk\Opencart\Identity($this->registry);
        $this->emit($identity->link((array) $body, (int) ($auth['claims']['customer_id'] ?? 0)));
    }

    public function unlink(): void
    {
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $linkId = (string) ($this->request->get['id'] ?? '');
        $identity = new \Shopwalk\Opencart\Identity($this->registry);
        $this->emit($identity->unlink($linkId, (int) ($auth['claims']['customer_id'] ?? 0)));
    }

    private function authenticate(): array
    {
        $oauth = new \Shopwalk\Opencart\OauthServer($this->registry);
        $claims = $oauth->introspect($_SERVER['HTTP_AUTHORIZATION'] ?? null);
        if ($claims === null) {
            return ['status' => 401, 'body' => \Shopwalk\Opencart\Response::error('unauthenticated', 'Bearer token required')];
        }
        return ['status' => 200, 'claims' => $claims];
    }

    private function emit(array $result): void
    {
        $this->response->addHeader('HTTP/1.1 ' . (int) ($result['status'] ?? 200));
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->addHeader('Access-Control-Allow-Origin: *');
        $this->response->setOutput(\Shopwalk\Opencart\Response::jsonEncode((array) ($result['body'] ?? [])));
    }
}
