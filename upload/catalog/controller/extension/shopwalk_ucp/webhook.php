<?php
/**
 * Catalog controller for agent webhook subscription management.
 *
 *   POST   /index.php?route=extension/shopwalk_ucp/webhook/subscribe
 *   DELETE /index.php?route=extension/shopwalk_ucp/webhook/unsubscribe&id=<id>
 *   GET    /index.php?route=extension/shopwalk_ucp/webhook/list
 */

namespace Opencart\Catalog\Controller\Extension\ShopwalkUcp;

require_once DIR_SYSTEM . 'library/shopwalk_opencart/bootstrap.php';

class Webhook extends \Opencart\System\Engine\Controller
{
    public function subscribe(): void
    {
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        if (empty($body['callback_url'])) {
            $this->emit(['status' => 422, 'body' => \Shopwalk\Opencart\Response::error('missing_callback_url', 'callback_url is required')]);
            return;
        }
        $subs = new \Shopwalk\Opencart\WebhookSubscriptions($this->registry);
        $row = $subs->create([
            'client_id'    => (string) ($auth['claims']['client_id'] ?? ''),
            'callback_url' => (string) $body['callback_url'],
            'events'       => (string) ($body['events'] ?? 'order.*'),
            'secret'       => (string) ($body['secret'] ?? ''),
        ]);
        $this->emit(['status' => 201, 'body' => \Shopwalk\Opencart\Response::ok($row)]);
    }

    public function unsubscribe(): void
    {
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $id = (string) ($this->request->get['id'] ?? '');
        $subs = new \Shopwalk\Opencart\WebhookSubscriptions($this->registry);
        $subs->delete($id);
        $this->emit(['status' => 200, 'body' => \Shopwalk\Opencart\Response::ok(['id' => $id, 'status' => 'disabled'])]);
    }

    public function list(): void
    {
        $auth = $this->authenticate();
        if ($auth['status'] !== 200) {
            $this->emit($auth);
            return;
        }
        $subs = new \Shopwalk\Opencart\WebhookSubscriptions($this->registry);
        $this->emit(['status' => 200, 'body' => \Shopwalk\Opencart\Response::ok(['subscriptions' => $subs->listAll()])]);
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
