<?php
/**
 * Catalog controller for /.well-known/ucp and /.well-known/oauth-authorization-server.
 *
 * Routed via .htaccess rewrites (see upload/.htaccess-snippet) or via static
 * .php dispatchers at upload/.well-known/{ucp.php,oauth-authorization-server.php}.
 *
 * Invoked as:
 *   /index.php?route=extension/shopwalk_ucp/discovery.ucp
 *   /index.php?route=extension/shopwalk_ucp/discovery.oauth
 */

namespace Opencart\Catalog\Controller\Extension\ShopwalkUcp;

require_once DIR_SYSTEM . 'library/shopwalk_opencart/bootstrap.php';

class Discovery extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $this->ucp();
    }

    public function ucp(): void
    {
        $discovery = new \Shopwalk\Opencart\Discovery($this->registry);
        $this->emitJson($discovery->profile(), 200);
    }

    public function oauth(): void
    {
        $discovery = new \Shopwalk\Opencart\Discovery($this->registry);
        $this->emitJson($discovery->oauthMeta(), 200);
    }

    private function emitJson(array $body, int $status): void
    {
        $this->response->addHeader('HTTP/1.1 ' . $status);
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->addHeader('Cache-Control: public, max-age=300');
        $this->response->addHeader('Access-Control-Allow-Origin: *');
        $this->response->setOutput(\Shopwalk\Opencart\Response::jsonEncode($body));
    }
}
