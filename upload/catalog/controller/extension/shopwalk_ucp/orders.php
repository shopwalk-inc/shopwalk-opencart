<?php
/**
 * Catalog controller for UCP orders read API.
 *
 * Routes:
 *   GET /index.php?route=extension/shopwalk_ucp/orders
 *   GET /index.php?route=extension/shopwalk_ucp/orders/fetch&id=<id>
 *
 * Rewritten from /ucp/v1/orders and /ucp/v1/orders/{id}.
 */

namespace Opencart\Catalog\Controller\Extension\ShopwalkUcp;

require_once DIR_SYSTEM . 'library/shopwalk_opencart/bootstrap.php';

class Orders extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $orders = new \Shopwalk\Opencart\Orders($this->registry);
        $this->emit($orders->listOrders($this->request->get, (int) ($auth['claims']['customer_id'] ?? 0)));
    }

    public function fetch(): void
    {
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $id = (int) ($this->request->get['id'] ?? 0);
        $orders = new \Shopwalk\Opencart\Orders($this->registry);
        $this->emit($orders->fetchOrder($id));
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
