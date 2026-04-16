<?php
/**
 * Catalog controller for UCP checkout-sessions.
 *
 * Routes:
 *   POST  /index.php?route=extension/shopwalk_ucp/checkout
 *   PUT   /index.php?route=extension/shopwalk_ucp/checkout/update&id=<id>
 *   POST  /index.php?route=extension/shopwalk_ucp/checkout/complete&id=<id>
 *   POST  /index.php?route=extension/shopwalk_ucp/checkout/cancel&id=<id>
 *   GET   /index.php?route=extension/shopwalk_ucp/checkout/fetch&id=<id>
 *
 * Client-friendly aliases are supplied by the .htaccess rewrites:
 *   POST /ucp/v1/checkout-sessions
 *   PUT  /ucp/v1/checkout-sessions/{id}
 *   POST /ucp/v1/checkout-sessions/{id}/complete
 *   POST /ucp/v1/checkout-sessions/{id}/cancel
 *   GET  /ucp/v1/checkout-sessions/{id}
 */

namespace Opencart\Catalog\Controller\Extension\ShopwalkUcp;

require_once DIR_SYSTEM . 'library/shopwalk_ucp/bootstrap.php';

class Checkout extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        if ($this->method() !== 'POST') {
            $this->emit(['body' => \Shopwalk\Ucp\Response::error('method_not_allowed', 'Use POST to create'), 'status' => 405]);
            return;
        }
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $body = $this->parseBody();
        $checkout = new \Shopwalk\Ucp\Checkout($this->registry);
        $idem = new \Shopwalk\Ucp\Idempotency($this->registry);
        $raw = (string) file_get_contents('php://input');
        $result = $idem->remember(
            $this->header('Idempotency-Key'),
            (string) $auth['client_id'],
            $raw,
            static fn() => $checkout->create($body, $auth['client_id'])
        );
        $this->emit(['body' => $result['body'], 'status' => $result['status']]);
    }

    public function update(): void
    {
        if (!in_array($this->method(), ['PUT', 'POST'], true)) {
            $this->emit(['body' => \Shopwalk\Ucp\Response::error('method_not_allowed', 'Use PUT'), 'status' => 405]);
            return;
        }
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $id = (string) ($this->request->get['id'] ?? '');
        $checkout = new \Shopwalk\Ucp\Checkout($this->registry);
        $this->emit($checkout->update($id, $this->parseBody()));
    }

    public function complete(): void
    {
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $id = (string) ($this->request->get['id'] ?? '');
        $checkout = new \Shopwalk\Ucp\Checkout($this->registry);
        $idem = new \Shopwalk\Ucp\Idempotency($this->registry);
        $raw = (string) file_get_contents('php://input');
        $result = $idem->remember(
            $this->header('Idempotency-Key'),
            (string) $auth['client_id'],
            $raw,
            fn() => $checkout->complete($id, $this->parseBody())
        );
        $this->emit(['body' => $result['body'], 'status' => $result['status']]);
    }

    public function cancel(): void
    {
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $id = (string) ($this->request->get['id'] ?? '');
        $checkout = new \Shopwalk\Ucp\Checkout($this->registry);
        $this->emit($checkout->cancel($id));
    }

    public function fetch(): void
    {
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $id = (string) ($this->request->get['id'] ?? '');
        $checkout = new \Shopwalk\Ucp\Checkout($this->registry);
        $this->emit($checkout->fetch($id));
    }

    private function authenticate(): array
    {
        $signing = new \Shopwalk\Ucp\Signing($this->registry);
        $raw = (string) file_get_contents('php://input');
        if (!$signing->verifyRequestSignature($raw, $this->header('Request-Signature'))) {
            return ['status' => 401, 'body' => \Shopwalk\Ucp\Response::error('invalid_signature', 'Request-Signature verification failed')];
        }
        $oauth = new \Shopwalk\Ucp\OauthServer($this->registry);
        $claims = $oauth->introspect($this->header('Authorization'));
        if ($claims === null) {
            return ['status' => 401, 'body' => \Shopwalk\Ucp\Response::error('unauthenticated', 'Bearer token required')];
        }
        return ['status' => 200, 'client_id' => (string) $claims['client_id'], 'claims' => $claims];
    }

    private function parseBody(): array
    {
        $raw = (string) file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    private function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    private function emit(array $result): void
    {
        $status = (int) ($result['status'] ?? 200);
        $this->response->addHeader('HTTP/1.1 ' . $status);
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->addHeader('Access-Control-Allow-Origin: *');
        $this->response->setOutput(\Shopwalk\Ucp\Response::jsonEncode((array) ($result['body'] ?? [])));
    }
}
