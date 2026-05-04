<?php
/**
 * Catalog controller for OAuth 2.0 endpoints.
 *
 * Routes:
 *   GET  /index.php?route=extension/shopwalk_ucp/oauth/authorize
 *   POST /index.php?route=extension/shopwalk_ucp/oauth/token
 *   POST /index.php?route=extension/shopwalk_ucp/oauth/revoke
 *   GET  /index.php?route=extension/shopwalk_ucp/oauth/userinfo
 *   POST /index.php?route=extension/shopwalk_ucp/oauth/register
 */

namespace Opencart\Catalog\Controller\Extension\ShopwalkUcp;

require_once DIR_SYSTEM . 'library/shopwalk_opencart/bootstrap.php';

class Oauth extends \Opencart\System\Engine\Controller
{
    public function authorize(): void
    {
        $server = new \Shopwalk\Opencart\OauthServer($this->registry);
        $customerId = (int) ($this->customer?->getId() ?? 0);
        $out = $server->authorize($this->request->get, $customerId);
        if (isset($out['redirect'])) {
            $this->response->redirect($out['redirect']);
            return;
        }
        $this->emit($out);
    }

    public function token(): void
    {
        $body = $this->parseFormOrJson();
        $server = new \Shopwalk\Opencart\OauthServer($this->registry);
        $this->emit($server->token($body));
    }

    public function revoke(): void
    {
        $body = $this->parseFormOrJson();
        $server = new \Shopwalk\Opencart\OauthServer($this->registry);
        $this->emit($server->revoke($body));
    }

    public function userinfo(): void
    {
        $server = new \Shopwalk\Opencart\OauthServer($this->registry);
        $this->emit($server->userinfo($_SERVER['HTTP_AUTHORIZATION'] ?? null));
    }

    public function register(): void
    {
        $body = $this->parseFormOrJson();
        if (empty($body['name']) || empty($body['redirect_uris'])) {
            $this->emit(['status' => 422, 'body' => \Shopwalk\Opencart\Response::error('invalid_request', 'name and redirect_uris required')]);
            return;
        }
        $clients = new \Shopwalk\Opencart\OauthClients($this->registry);
        $credentials = $clients->register([
            'name'          => (string) $body['name'],
            'redirect_uris' => (array) $body['redirect_uris'],
            'profile_url'   => (string) ($body['profile_url'] ?? ''),
            'scopes'        => (string) ($body['scopes'] ?? 'ucp:scopes:checkout_session ucp:scopes:orders'),
        ]);
        $this->emit(['status' => 201, 'body' => \Shopwalk\Opencart\Response::ok([
            'client_id'     => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'client_id_issued_at' => time(),
            'scopes'        => (string) ($body['scopes'] ?? 'ucp:scopes:checkout_session ucp:scopes:orders'),
            'redirect_uris' => $body['redirect_uris'],
        ])]);
    }

    private function parseFormOrJson(): array
    {
        if (!empty($_POST)) {
            return $_POST;
        }
        $raw = (string) file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        parse_str($raw, $form);
        return is_array($form) ? $form : [];
    }

    private function emit(array $result): void
    {
        $this->response->addHeader('HTTP/1.1 ' . (int) ($result['status'] ?? 200));
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->addHeader('Access-Control-Allow-Origin: *');
        $this->response->setOutput(\Shopwalk\Opencart\Response::jsonEncode((array) ($result['body'] ?? [])));
    }
}
