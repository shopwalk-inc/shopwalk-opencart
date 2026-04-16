<?php
/**
 * Catalog controller for UCP direct checkout — POST /ucp/v1/checkout
 *
 * Rewrites to /index.php?route=extension/shopwalk_ucp/direct
 */

namespace Opencart\Catalog\Controller\Extension\ShopwalkUcp;

require_once DIR_SYSTEM . 'library/shopwalk_ucp/bootstrap.php';

class Direct extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->emit(['status' => 405, 'body' => \Shopwalk\Ucp\Response::error('method_not_allowed', 'Use POST')]);
            return;
        }
        $signing = new \Shopwalk\Ucp\Signing($this->registry);
        $raw = (string) file_get_contents('php://input');
        $reqSig = $_SERVER['HTTP_REQUEST_SIGNATURE'] ?? null;
        if (!$signing->verifyRequestSignature($raw, $reqSig)) {
            $this->emit(['status' => 401, 'body' => \Shopwalk\Ucp\Response::error('invalid_signature', 'Request-Signature failed')]);
            return;
        }
        $oauth = new \Shopwalk\Ucp\OauthServer($this->registry);
        $claims = $oauth->introspect($_SERVER['HTTP_AUTHORIZATION'] ?? null);
        if ($claims === null) {
            $this->emit(['status' => 401, 'body' => \Shopwalk\Ucp\Response::error('unauthenticated', 'Bearer token required')]);
            return;
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $this->emit(['status' => 400, 'body' => \Shopwalk\Ucp\Response::error('invalid_json', 'Request body must be JSON')]);
            return;
        }

        $idem = new \Shopwalk\Ucp\Idempotency($this->registry);
        $direct = new \Shopwalk\Ucp\DirectCheckout($this->registry);
        $result = $idem->remember(
            $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
            (string) $claims['client_id'],
            $raw,
            static fn() => $direct->create($body, (string) $claims['client_id'])
        );
        $this->emit(['status' => $result['status'], 'body' => $result['body']]);
    }

    private function emit(array $result): void
    {
        $this->response->addHeader('HTTP/1.1 ' . (int) $result['status']);
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->addHeader('Access-Control-Allow-Origin: *');
        $this->response->setOutput(\Shopwalk\Ucp\Response::jsonEncode((array) $result['body']));
    }
}
