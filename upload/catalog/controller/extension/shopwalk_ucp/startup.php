<?php
/**
 * Catalog startup controller — wires OpenCart events to UCP handlers.
 *
 * Registered as a catalog startup action in `admin/controller/extension/shopwalk_ucp/module/ucp.php`
 * via `registry->get('load')->controller('extension/shopwalk_ucp/startup/register')`.
 *
 * The `order.history.add` event fires webhooks on every order status change.
 */

namespace Opencart\Catalog\Controller\Extension\ShopwalkUcp;

require_once DIR_SYSTEM . 'library/shopwalk_ucp/bootstrap.php';

class Startup extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        // no-op — presence of the class lets the admin installer wire up events.
    }

    /**
     * OpenCart event handler — called after checkout/order/addHistory completes.
     * Signature matches OpenCart's event callback: ($route, $args, $output).
     */
    public function onOrderHistoryAdded(string $route, array $args, &$output): void
    {
        if (empty($args[0])) {
            return;
        }
        $orderId = (int) $args[0];
        $statusId = (int) ($args[1] ?? 0);
        $delivery = new \Shopwalk\Ucp\WebhookDelivery($this->registry);
        try {
            $delivery->onOrderStatusChanged($orderId, 0, $statusId);
            $delivery->flushPending(10);
        } catch (\Throwable $e) {
            // Swallow — never break the merchant's checkout on a webhook error.
            $this->registry->get('log')?->write('opencart-ucp webhook error: ' . $e->getMessage());
        }
    }
}
