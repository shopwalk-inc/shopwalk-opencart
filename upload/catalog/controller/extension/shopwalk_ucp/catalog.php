<?php
/**
 * Catalog controller for UCP catalog.
 *
 *   GET /ucp/v1/catalog/products          → index()
 *   GET /ucp/v1/catalog/products/{id}     → fetch()
 */

namespace Opencart\Catalog\Controller\Extension\ShopwalkUcp;

require_once DIR_SYSTEM . 'library/shopwalk_ucp/bootstrap.php';

class Catalog extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $catalog = new \Shopwalk\Ucp\Catalog($this->registry);
        $this->emit($catalog->listProducts($this->request->get));
    }

    public function fetch(): void
    {
        $catalog = new \Shopwalk\Ucp\Catalog($this->registry);
        $this->emit($catalog->fetchProduct((int) ($this->request->get['id'] ?? 0)));
    }

    private function emit(array $result): void
    {
        $this->response->addHeader('HTTP/1.1 ' . (int) ($result['status'] ?? 200));
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->addHeader('Access-Control-Allow-Origin: *');
        $this->response->addHeader('Cache-Control: public, max-age=60');
        $this->response->setOutput(\Shopwalk\Ucp\Response::jsonEncode((array) ($result['body'] ?? [])));
    }
}
