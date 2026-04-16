<?php
/**
 * Shopwalk\Ucp\Orders — read-only UCP Orders API.
 *
 * Shapes OpenCart orders into UCP order entities:
 *   - line_items[].quantity is { original, total, fulfilled }
 *   - fulfillment.expectations[] describes what will ship
 *   - fulfillment.events[] is an append-only log from order_history
 *   - adjustments[] holds refund/return deltas
 *   - totals[] is a typed minor-unit array
 */

declare(strict_types=1);

namespace Shopwalk\Ucp;

final class Orders
{
    private \Registry $registry;
    private \DB $db;

    public function __construct(\Registry $registry)
    {
        $this->registry = $registry;
        $this->db = $registry->get('db');
    }

    public function listOrders(array $query, ?int $customerId): array
    {
        $limit = max(1, min(100, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $where = '1 = 1';
        if ($customerId !== null && $customerId > 0) {
            $where .= ' AND `customer_id` = ' . $customerId;
        }
        $r = $this->db->query(
            "SELECT `order_id` FROM `" . DB_PREFIX . "order` WHERE {$where} " .
            "ORDER BY `order_id` DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $orders = [];
        foreach ($r->rows as $row) {
            $order = $this->fetchEntity((int) $row['order_id']);
            if ($order !== null) {
                $orders[] = $order;
            }
        }
        return ['status' => 200, 'body' => Response::ok(['orders' => $orders])];
    }

    public function fetchOrder(int $orderId): array
    {
        $entity = $this->fetchEntity($orderId);
        if ($entity === null) {
            return ['status' => 404, 'body' => Response::error('order_not_found', (string) $orderId)];
        }
        return ['status' => 200, 'body' => Response::ok($entity)];
    }

    private function fetchEntity(int $orderId): ?array
    {
        $r = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE `order_id` = {$orderId} LIMIT 1");
        if ($r->num_rows === 0) {
            return null;
        }
        $o = $r->row;
        $currency = strtoupper((string) $o['currency_code']);

        $lineRows = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "order_product` WHERE `order_id` = {$orderId}"
        )->rows;
        $lineItems = [];
        foreach ($lineRows as $idx => $lp) {
            $qty = (int) $lp['quantity'];
            $unit = Response::toMinor((float) $lp['price'], $currency);
            $total = Response::toMinor((float) $lp['total'], $currency);
            $lineItems[] = [
                'id'   => 'li_' . ((int) $lp['order_product_id']),
                'item' => [
                    'id'    => (string) $lp['product_id'],
                    'title' => (string) $lp['name'],
                    'price' => $unit,
                ],
                'quantity' => [
                    'original'  => $qty,
                    'total'     => $qty,
                    'fulfilled' => $this->fulfilledQty((int) $o['order_status_id'], $qty),
                ],
                'status' => $this->lineItemStatus((int) $o['order_status_id']),
                'totals' => [
                    ['type' => 'subtotal', 'amount' => $total],
                    ['type' => 'total',    'amount' => $total],
                ],
            ];
        }

        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $langId = (int) ($config->get('config_language_id') ?? 1);
        $historyRows = $this->db->query(
            "SELECT oh.*, os.`name` AS status_name FROM `" . DB_PREFIX . "order_history` oh " .
            "LEFT JOIN `" . DB_PREFIX . "order_status` os " .
            "  ON os.`order_status_id` = oh.`order_status_id` AND os.`language_id` = {$langId} " .
            "WHERE oh.`order_id` = {$orderId} ORDER BY oh.`date_added` ASC"
        )->rows;
        $events = [];
        foreach ($historyRows as $idx => $h) {
            $events[] = [
                'id'          => 'evt_' . ((int) $h['order_history_id']),
                'occurred_at' => gmdate('c', strtotime((string) $h['date_added'])),
                'type'        => $this->mapEventType((string) ($h['status_name'] ?? '')),
                'description' => (string) ($h['status_name'] ?? ''),
                'line_items'  => array_map(static fn($li) => ['id' => $li['id'], 'quantity' => $li['quantity']['original']], $lineItems),
            ];
        }

        $totalsRows = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "order_total` WHERE `order_id` = {$orderId} ORDER BY `sort_order` ASC"
        )->rows;
        $totals = [];
        foreach ($totalsRows as $tr) {
            $totals[] = [
                'type'   => (string) $tr['code'],
                'amount' => Response::toMinor((float) $tr['value'], $currency),
            ];
        }

        $dest = Response::address([
            'line1'    => $o['shipping_address_1'],
            'line2'    => $o['shipping_address_2'],
            'city'     => $o['shipping_city'],
            'state'    => $o['shipping_zone'],
            'postcode' => $o['shipping_postcode'],
            'country'  => $o['shipping_country'],
        ]);

        return [
            'id'            => (string) $orderId,
            'label'         => '#' . $orderId,
            'checkout_id'   => $this->checkoutIdFromCustomField((string) ($o['custom_field'] ?? '')),
            'permalink_url' => $this->orderLink($orderId, $o),
            'currency'      => $currency,
            'line_items'    => $lineItems,
            'fulfillment' => [
                'expectations' => [[
                    'id'             => 'exp_1',
                    'line_items'     => array_map(static fn($li) => ['id' => $li['id'], 'quantity' => $li['quantity']['original']], $lineItems),
                    'method_type'    => 'shipping',
                    'destination'    => $dest,
                    'description'    => 'Shipping to ' . ($dest['address_locality'] ?? ''),
                    'fulfillable_on' => 'now',
                ]],
                'events' => $events,
            ],
            'adjustments' => [],
            'totals'      => $totals,
            'messages'    => [],
        ];
    }

    private function fulfilledQty(int $statusId, int $orderedQty): int
    {
        // OpenCart doesn't track per-line fulfillment by default. Use order-level
        // status as a proxy: complete (5) means everything fulfilled.
        return $statusId === 5 ? $orderedQty : 0;
    }

    private function lineItemStatus(int $statusId): string
    {
        return match ($statusId) {
            1, 2, 3 => 'processing',
            5       => 'delivered',
            7       => 'canceled',
            11      => 'refunded',
            default => 'pending',
        };
    }

    private function mapEventType(string $statusName): string
    {
        $n = strtolower($statusName);
        if (str_contains($n, 'complet')) return 'delivered';
        if (str_contains($n, 'ship'))    return 'shipped';
        if (str_contains($n, 'cancel'))  return 'canceled';
        if (str_contains($n, 'refund'))  return 'refunded';
        if (str_contains($n, 'process')) return 'processing';
        return 'status_changed';
    }

    private function checkoutIdFromCustomField(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && isset($decoded['checkout_id']) ? (string) $decoded['checkout_id'] : null;
    }

    private function orderLink(int $orderId, array $o): string
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $base = (string) ($config->get('config_url') ?? $config->get('config_ssl') ?? '');
        return rtrim($base, '/') . '/index.php?route=account/order/info&order_id=' . $orderId;
    }
}
