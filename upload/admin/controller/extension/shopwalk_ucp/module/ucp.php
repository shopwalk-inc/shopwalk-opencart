<?php
/**
 * Admin controller — Shopwalk UCP module dashboard.
 *
 *   index()    Render the dashboard (self-test, signing fingerprint,
 *              webhook subscriptions list, Shopwalk connect CTA).
 *   install()  Create UCP tables, generate signing keypair, register events.
 *   uninstall() Drop events + (optional) data.
 *   save()     Persist admin settings (shopwalk_license_key, agent_secret).
 *   self_test() AJAX endpoint called from the dashboard to refresh results.
 */

namespace Opencart\Admin\Controller\Extension\ShopwalkUcp\Module;

require_once DIR_SYSTEM . 'library/shopwalk_ucp/bootstrap.php';

class Ucp extends \Opencart\System\Engine\Controller
{
    private const EVENT_CODE = 'shopwalk_ucp';

    public function index(): void
    {
        $this->load->language('extension/shopwalk_ucp/module/ucp');
        $this->document->setTitle((string) $this->language->get('heading_title'));

        if (($this->request->server['REQUEST_METHOD'] ?? '') === 'POST' && $this->validate()) {
            $this->saveSetting('shopwalk_ucp_status',   (int) ($this->request->post['shopwalk_ucp_status'] ?? 0));
            $this->saveSetting('shopwalk_ucp_agent_secret', (string) ($this->request->post['shopwalk_ucp_agent_secret'] ?? ''));
            $this->saveSetting('shopwalk_ucp_license_key',   (string) ($this->request->post['shopwalk_ucp_license_key'] ?? ''));
            $this->session->data['success'] = (string) $this->language->get('text_success');
            $this->response->redirect($this->url->link(
                'extension/shopwalk_ucp/module/ucp',
                'user_token=' . $this->session->data['user_token']
            ));
            return;
        }

        $signing = new \Shopwalk\Ucp\Signing($this->registry);
        $selfTest = (new \Shopwalk\Ucp\SelfTest($this->registry))->run();
        $subs = new \Shopwalk\Ucp\WebhookSubscriptions($this->registry);
        $clients = new \Shopwalk\Ucp\OauthClients($this->registry);

        $data = [
            'heading_title'       => $this->language->get('heading_title'),
            'user_token'          => $this->session->data['user_token'],
            'action'              => $this->url->link('extension/shopwalk_ucp/module/ucp', 'user_token=' . $this->session->data['user_token']),
            'cancel'              => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module'),
            'spec_version'        => SHOPWALK_UCP_SPEC_VERSION,
            'plugin_version'      => SHOPWALK_UCP_VERSION,
            'signing_fingerprint' => $signing->fingerprint(),
            'self_test'           => $selfTest,
            'subscriptions'       => $subs->listAll(),
            'clients'             => $clients->all(),
            'status'              => (int) $this->config->get('shopwalk_ucp_status'),
            'license_key'         => (string) $this->config->get('shopwalk_ucp_license_key'),
            'agent_secret'        => (string) $this->config->get('shopwalk_ucp_agent_secret'),
            'shopwalk_connect_url' => 'https://shopwalk.com/partners/signup',
            'discovery_url'       => $this->storeUrl() . '/.well-known/ucp',
        ];

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/shopwalk_ucp/module/ucp', $data));
    }

    public function install(): void
    {
        $storage = new \Shopwalk\Ucp\Storage($this->registry);
        $storage->install();
        (new \Shopwalk\Ucp\Signing($this->registry))->keypair();
        $this->registerEvents();
    }

    public function uninstall(): void
    {
        $this->unregisterEvents();
        if (!empty($this->request->get['drop_data'])) {
            (new \Shopwalk\Ucp\Storage($this->registry))->uninstall(true);
        }
    }

    public function self_test(): void
    {
        $results = (new \Shopwalk\Ucp\SelfTest($this->registry))->run();
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode(['results' => $results], JSON_UNESCAPED_SLASHES));
    }

    private function validate(): bool
    {
        return $this->user->hasPermission('modify', 'extension/shopwalk_ucp/module/ucp');
    }

    private function saveSetting(string $key, mixed $value): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('shopwalk_ucp', [$key => $value]);
    }

    private function registerEvents(): void
    {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode(self::EVENT_CODE);
        $this->model_setting_event->addEvent([
            'code'        => self::EVENT_CODE,
            'description' => 'Shopwalk UCP — fire order status webhooks',
            'trigger'     => 'admin/model/sale/order/addHistory/after',
            'action'      => 'extension/shopwalk_ucp/startup/onOrderHistoryAdded',
            'status'      => true,
            'sort_order'  => 1,
        ]);
        $this->model_setting_event->addEvent([
            'code'        => self::EVENT_CODE,
            'description' => 'Shopwalk UCP — fire order status webhooks (catalog)',
            'trigger'     => 'catalog/model/checkout/order/addHistory/after',
            'action'      => 'extension/shopwalk_ucp/startup/onOrderHistoryAdded',
            'status'      => true,
            'sort_order'  => 1,
        ]);
    }

    private function unregisterEvents(): void
    {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode(self::EVENT_CODE);
    }

    private function storeUrl(): string
    {
        $url = (string) ($this->config->get('config_url') ?? $this->config->get('config_ssl') ?? '');
        return rtrim($url, '/');
    }
}
