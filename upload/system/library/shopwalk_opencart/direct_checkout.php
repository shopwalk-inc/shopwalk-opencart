<?php
/**
 * Shopwalk\Opencart\DirectCheckout — UCP direct checkout extension.
 *
 * One call, no session machinery:
 *   POST /ucp/v1/checkout { items, customer, shipping_address, return_url, metadata }
 *   → { order_id, order_key, status: "pending", payment_url, totals..., expires_at }
 *
 * Customer completes payment on OpenCart's native checkout (whatever gateways
 * the merchant has installed — PayPal, Stripe, Square, bank transfer, etc.).
 * Shopwalk never touches money. See UCP_DIRECT_CHECKOUT.md in shopwalk-infra.
 */

declare(strict_types=1);

namespace Shopwalk\Opencart;

final class DirectCheckout
{
    private \Registry $registry;
    private \DB $db;

    public function __construct(\Registry $registry)
    {
        $this->registry = $registry;
        $this->db = $registry->get('db');
    }

    public function create(array $body, string $clientId = ''): array
    {
        $items = $body['items'] ?? [];
        $customer = $body['customer'] ?? [];
        $shipping = $body['shipping_address'] ?? [];
        $metadata = $body['metadata'] ?? [];
        $returnUrl = (string) ($body['return_url'] ?? '');

        if (!is_array($items) || empty($items)) {
            return ['status' => 422, 'body' => Response::error('missing_items', 'items is required')];
        }
        if (empty($customer['email'])) {
            return ['status' => 422, 'body' => Response::error('missing_email', 'customer.email is required')];
        }
        foreach (['address_1','city','postcode','country'] as $k) {
            if (empty($shipping[$k])) {
                return ['status' => 422, 'body' => Response::error('missing_address', "shipping_address.{$k} required")];
            }
        }

        $subtotalMinor = 0;
        $resolved = [];
        $currency = $this->currency();
        foreach ($items as $raw) {
            $productId = (int) ($raw['product_id'] ?? 0);
            $qty = max(1, (int) ($raw['quantity'] ?? 1));
            $product = $this->loadProduct($productId);
            if ($product === null) {
                return ['status' => 422, 'body' => Response::error('product_unavailable', "Product {$productId} unavailable", 'unrecoverable', ['product_id' => $productId])];
            }
            $priceMinor = Response::toMinor((float) $product['price'], $currency);
            $lineTotal = $priceMinor * $qty;
            $subtotalMinor += $lineTotal;
            $resolved[] = [
                'product_id' => $productId,
                'name'       => (string) $product['name'],
                'price'      => $priceMinor,
                'quantity'   => $qty,
                'total'      => $lineTotal,
            ];
        }

        $now = date('Y-m-d H:i:s');
        $shippingMinor = $subtotalMinor >= 5000 ? 0 : 599;
        $taxMinor = (int) round(($subtotalMinor + $shippingMinor) * 0.08);
        $totalMinor = $subtotalMinor + $shippingMinor + $taxMinor;

        $this->db->query("INSERT INTO `" . DB_PREFIX . "order` SET " .
            "`store_id` = 0, " .
            "`customer_id` = 0, " .
            "`customer_group_id` = 1, " .
            "`firstname` = '" . $this->db->escape((string) ($customer['first_name'] ?? '')) . "', " .
            "`lastname`  = '" . $this->db->escape((string) ($customer['last_name'] ?? '')) . "', " .
            "`email`     = '" . $this->db->escape((string) $customer['email']) . "', " .
            "`telephone` = '" . $this->db->escape((string) ($customer['phone'] ?? '')) . "', " .
            "`shipping_firstname` = '" . $this->db->escape((string) ($shipping['first_name'] ?? $customer['first_name'] ?? '')) . "', " .
            "`shipping_lastname`  = '" . $this->db->escape((string) ($shipping['last_name'] ?? $customer['last_name'] ?? '')) . "', " .
            "`shipping_address_1` = '" . $this->db->escape((string) $shipping['address_1']) . "', " .
            "`shipping_address_2` = '" . $this->db->escape((string) ($shipping['address_2'] ?? '')) . "', " .
            "`shipping_city`      = '" . $this->db->escape((string) $shipping['city']) . "', " .
            "`shipping_postcode`  = '" . $this->db->escape((string) $shipping['postcode']) . "', " .
            "`shipping_zone`      = '" . $this->db->escape((string) ($shipping['state'] ?? '')) . "', " .
            "`shipping_country`   = '" . $this->db->escape((string) $shipping['country']) . "', " .
            "`payment_firstname` = '" . $this->db->escape((string) ($customer['first_name'] ?? '')) . "', " .
            "`payment_lastname`  = '" . $this->db->escape((string) ($customer['last_name'] ?? '')) . "', " .
            "`payment_address_1` = '" . $this->db->escape((string) $shipping['address_1']) . "', " .
            "`payment_city`      = '" . $this->db->escape((string) $shipping['city']) . "', " .
            "`payment_postcode`  = '" . $this->db->escape((string) $shipping['postcode']) . "', " .
            "`payment_zone`      = '" . $this->db->escape((string) ($shipping['state'] ?? '')) . "', " .
            "`payment_country`   = '" . $this->db->escape((string) $shipping['country']) . "', " .
            "`payment_method` = 'UCP deferred (native gateway)', " .
            "`shipping_method` = 'UCP Shipping', " .
            "`currency_code` = '" . $this->db->escape($currency) . "', " .
            "`currency_value` = 1.0, " .
            "`total` = " . ($totalMinor / 100.0) . ", " .
            "`order_status_id` = 0, " .
            "`ip` = '" . $this->db->escape((string) ($_SERVER['REMOTE_ADDR'] ?? '')) . "', " .
            "`date_added` = '" . $now . "', " .
            "`date_modified` = '" . $now . "'");
        $orderId = (int) $this->db->getLastId();
        $orderKey = bin2hex(random_bytes(16));

        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "order_history` SET " .
            "`order_id` = {$orderId}, `order_status_id` = 0, `notify` = 0, " .
            "`comment` = 'Created by UCP agent (" . $this->db->escape($clientId) . ")', " .
            "`date_added` = '" . $now . "'"
        );

        foreach ($resolved as $li) {
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "order_product` SET " .
                "`order_id` = {$orderId}, " .
                "`product_id` = " . $li['product_id'] . ", " .
                "`name` = '" . $this->db->escape($li['name']) . "', " .
                "`model` = '', " .
                "`quantity` = " . $li['quantity'] . ", " .
                "`price` = " . ($li['price'] / 100.0) . ", " .
                "`total` = " . ($li['total'] / 100.0) . ", " .
                "`tax` = 0, `reward` = 0"
            );
        }

        foreach (
            [
                ['sub_total', 'Sub-Total',  $subtotalMinor, 1],
                ['shipping',  'Shipping',   $shippingMinor, 3],
                ['tax',       'Tax',        $taxMinor,      5],
                ['total',     'Total',      $totalMinor,    9],
            ] as $row
        ) {
            [$code, $title, $amount, $sort] = $row;
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "order_total` SET " .
                "`order_id` = {$orderId}, " .
                "`extension` = 'total', " .
                "`code` = '" . $this->db->escape($code) . "', " .
                "`title` = '" . $this->db->escape($title) . "', " .
                "`value` = " . ($amount / 100.0) . ", " .
                "`sort_order` = " . $sort
            );
        }

        $paymentUrl = $this->paymentUrl($orderId, $orderKey);
        $expiresAt = time() + SHOPWALK_UCP_SESSION_TTL;

        $this->persistMetadata($orderId, $orderKey, $returnUrl, $metadata, $clientId);

        return ['status' => 200, 'body' => Response::ok([
            'order_id'       => $orderId,
            'order_key'      => $orderKey,
            'status'         => 'pending',
            'payment_url'    => $paymentUrl,
            'subtotal'       => $subtotalMinor,
            'shipping_total' => $shippingMinor,
            'tax_total'      => $taxMinor,
            'total'          => $totalMinor,
            'currency'       => $currency,
            'items'          => array_map(
                static fn($li) => [
                    'product_id' => $li['product_id'],
                    'name'       => $li['name'],
                    'quantity'   => $li['quantity'],
                    'price'      => $li['price'],
                ],
                $resolved
            ),
            'expires_at'     => gmdate('c', $expiresAt),
        ])];
    }

    private function persistMetadata(int $orderId, string $orderKey, string $returnUrl, array $metadata, string $clientId): void
    {
        // OpenCart doesn't have generic order-meta. Use the custom_field column as JSON.
        $meta = json_encode([
            'order_key'   => $orderKey,
            'return_url'  => $returnUrl,
            'client_id'   => $clientId,
            'metadata'    => $metadata,
            'ucp_source'  => 'ucp-agent',
        ], JSON_UNESCAPED_SLASHES);
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "order` SET " .
            "`custom_field` = '" . $this->db->escape((string) $meta) . "' " .
            "WHERE `order_id` = {$orderId}"
        );
    }

    private function paymentUrl(int $orderId, string $key): string
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $base = (string) ($config->get('config_url') ?? $config->get('config_ssl') ?? '');
        return rtrim($base, '/') . '/index.php?route=checkout/checkout&order_id=' . $orderId . '&key=' . urlencode($key);
    }

    private function loadProduct(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }
        $r = $this->db->query(
            "SELECT p.`product_id`, p.`price`, p.`quantity`, p.`subtract`, p.`status`, pd.`name` " .
            "FROM `" . DB_PREFIX . "product` p " .
            "LEFT JOIN `" . DB_PREFIX . "product_description` pd " .
            "  ON pd.`product_id` = p.`product_id` AND pd.`language_id` = " . $this->languageId() . " " .
            "WHERE p.`product_id` = {$productId} AND p.`status` = 1 LIMIT 1"
        );
        return $r->num_rows > 0 ? $r->row : null;
    }

    private function currency(): string
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        return strtoupper((string) ($config->get('config_currency') ?? 'USD'));
    }

    private function languageId(): int
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        return (int) ($config->get('config_language_id') ?? 1);
    }
}
