<?php
/**
 * Shopwalk\Opencart\Checkout — session-based UCP checkout.
 *
 * Lifecycle: incomplete → ready_for_complete → completed | canceled
 *                                             → requires_escalation
 *
 * Session rows live in {prefix}ucp_checkout_sessions. The actual OpenCart
 * order is not created until /complete is called, at which point we insert
 * into the `order` / `order_product` / `order_total` tables and flip the
 * session into `completed`.
 */

declare(strict_types=1);

namespace Shopwalk\Opencart;

final class Checkout
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
        $items = is_array($body['line_items'] ?? null) ? $body['line_items'] : [];
        if (empty($items)) {
            return ['status' => 422, 'body' => Response::error('missing_line_items', 'line_items is required')];
        }

        $normalized = [];
        $subtotal = 0;
        $currency = $this->currency();
        foreach ($items as $idx => $raw) {
            $productId = (int) ($raw['item']['id'] ?? $raw['product_id'] ?? 0);
            $qty = max(1, (int) ($raw['quantity'] ?? 1));
            $product = $this->loadProduct($productId);
            if ($product === null) {
                return ['status' => 422, 'body' => Response::error('product_unavailable', "Product {$productId} not found")];
            }
            if ((int) $product['subtract'] === 1 && (int) $product['quantity'] < $qty) {
                return ['status' => 422, 'body' => Response::error('out_of_stock', "Product {$productId} is out of stock")];
            }
            $lineId = 'li_' . ($idx + 1) . '_' . bin2hex(random_bytes(3));
            $priceMinor = Response::toMinor((float) $product['price'], $currency);
            $lineTotal = $priceMinor * $qty;
            $normalized[] = [
                'id' => $lineId,
                'item' => [
                    'id'        => (string) $productId,
                    'title'     => (string) $product['name'],
                    'price'     => $priceMinor,
                    'image_url' => $this->productImageUrl((string) $product['image']),
                ],
                'quantity' => $qty,
                'totals' => [
                    ['type' => 'subtotal', 'amount' => $lineTotal],
                    ['type' => 'total',    'amount' => $lineTotal],
                ],
            ];
            $subtotal += $lineTotal;
        }

        $sessionId = 'chk_' . bin2hex(random_bytes(10));
        $now = time();
        $expires = $now + SHOPWALK_UCP_SESSION_TTL;
        $totals = Response::totals(['subtotal' => $subtotal, 'total' => $subtotal]);

        $this->db->query(
            "INSERT INTO `" . SHOPWALK_UCP_TABLE_PREFIX . "checkout_sessions` " .
            "(`id`, `agent_client_id`, `status`, `line_items`, `totals`, `currency`, `created_at`, `updated_at`, `expires_at`) VALUES (" .
            "'" . $this->db->escape($sessionId) . "', " .
            "'" . $this->db->escape($clientId) . "', " .
            "'incomplete', " .
            "'" . $this->db->escape((string) json_encode($normalized, JSON_UNESCAPED_SLASHES)) . "', " .
            "'" . $this->db->escape((string) json_encode($totals, JSON_UNESCAPED_SLASHES)) . "', " .
            "'" . $this->db->escape($currency) . "', " .
            "{$now}, {$now}, {$expires})"
        );

        return ['status' => 200, 'body' => Response::ok([
            'id'         => $sessionId,
            'status'     => 'incomplete',
            'currency'   => $currency,
            'line_items' => $normalized,
            'totals'     => $totals,
            'messages'   => [Response::message('buyer_required', 'Provide buyer info to proceed.')],
            'expires_at' => gmdate('c', $expires),
        ])];
    }

    public function fetch(string $sessionId): array
    {
        $session = $this->load($sessionId);
        if ($session === null) {
            return ['status' => 404, 'body' => Response::error('session_not_found', $sessionId)];
        }
        return ['status' => 200, 'body' => Response::ok($this->shape($session))];
    }

    public function update(string $sessionId, array $body): array
    {
        $session = $this->load($sessionId);
        if ($session === null) {
            return ['status' => 404, 'body' => Response::error('session_not_found', $sessionId)];
        }
        if (!in_array($session['status'], ['incomplete', 'ready_for_complete'], true)) {
            return ['status' => 409, 'body' => Response::error('invalid_state', "Cannot update session in status {$session['status']}")];
        }
        if (isset($body['buyer']) && is_array($body['buyer'])) {
            $session['buyer'] = $body['buyer'];
        }
        if (isset($body['fulfillment']) && is_array($body['fulfillment'])) {
            $session['fulfillment'] = $this->buildFulfillment($session, $body['fulfillment']);
        }
        if (isset($body['payment']) && is_array($body['payment'])) {
            $session['payment'] = $body['payment'];
        }

        $messages = [];
        if (empty($session['buyer']['email'])) {
            $session['status'] = 'incomplete';
            $messages[] = ['type' => 'error', 'code' => 'missing', 'path' => '$.buyer.email', 'content' => 'Email required', 'severity' => 'recoverable'];
        } elseif (empty($session['fulfillment']['methods'][0]['destinations'][0])) {
            $session['status'] = 'incomplete';
            $messages[] = ['type' => 'error', 'code' => 'missing', 'path' => '$.fulfillment.methods[0].destinations', 'content' => 'Shipping destination required', 'severity' => 'recoverable'];
        } elseif (empty($session['fulfillment']['methods'][0]['groups'][0]['selected_option_id'])) {
            $session['status'] = 'incomplete';
            $messages[] = ['type' => 'error', 'code' => 'missing', 'path' => '$.fulfillment.methods[0].groups[0].selected_option_id', 'content' => 'Pick a shipping option', 'severity' => 'recoverable'];
        } else {
            $session['status'] = 'ready_for_complete';
            $messages[] = Response::message('ready', 'Call /complete to place the order.');
        }

        $session['totals'] = $this->recomputeTotals($session);
        $session['messages'] = $messages;
        $this->persist($session);
        return ['status' => 200, 'body' => Response::ok($this->shape($session))];
    }

    public function complete(string $sessionId, array $body): array
    {
        $session = $this->load($sessionId);
        if ($session === null) {
            return ['status' => 404, 'body' => Response::error('session_not_found', $sessionId)];
        }
        if ($session['status'] !== 'ready_for_complete') {
            return ['status' => 409, 'body' => Response::error('invalid_state', "Session must be ready_for_complete, was {$session['status']}")];
        }
        if (isset($body['payment']['instruments'][0])) {
            $session['payment'] = $body['payment'];
        }

        $orderId = $this->createOpencartOrder($session);
        if ($orderId === 0) {
            $session['status'] = 'requires_escalation';
            $this->persist($session);
            return ['status' => 502, 'body' => Response::error('order_create_failed', 'Could not persist OpenCart order')];
        }

        $session['status'] = 'completed';
        $session['opencart_order_id'] = $orderId;
        $this->persist($session);

        $payUrl = $this->paymentUrl($orderId);
        $shaped = $this->shape($session);
        $shaped['order'] = [
            'id'            => (string) $orderId,
            'label'         => '#' . $orderId,
            'permalink_url' => $payUrl,
        ];
        return ['status' => 200, 'body' => Response::ok($shaped)];
    }

    public function cancel(string $sessionId): array
    {
        $session = $this->load($sessionId);
        if ($session === null) {
            return ['status' => 404, 'body' => Response::error('session_not_found', $sessionId)];
        }
        $session['status'] = 'canceled';
        $this->persist($session);
        return ['status' => 200, 'body' => Response::ok($this->shape($session))];
    }

    private function load(string $sessionId): ?array
    {
        $r = $this->db->query(
            "SELECT * FROM `" . SHOPWALK_UCP_TABLE_PREFIX . "checkout_sessions` " .
            "WHERE `id` = '" . $this->db->escape($sessionId) . "' LIMIT 1"
        );
        if ($r->num_rows === 0) {
            return null;
        }
        $row = $r->row;
        foreach (['buyer', 'line_items', 'fulfillment', 'payment', 'totals', 'messages', 'metadata'] as $f) {
            if (isset($row[$f]) && $row[$f] !== null && $row[$f] !== '') {
                $row[$f] = json_decode((string) $row[$f], true);
            }
        }
        return $row;
    }

    private function persist(array $session): void
    {
        $now = time();
        $this->db->query(
            "UPDATE `" . SHOPWALK_UCP_TABLE_PREFIX . "checkout_sessions` SET " .
            "`status` = '" . $this->db->escape((string) $session['status']) . "', " .
            "`opencart_order_id` = " . ((int) ($session['opencart_order_id'] ?? 0)) . ", " .
            "`buyer` = '" . $this->db->escape((string) json_encode($session['buyer'] ?? null, JSON_UNESCAPED_SLASHES)) . "', " .
            "`line_items` = '" . $this->db->escape((string) json_encode($session['line_items'] ?? [], JSON_UNESCAPED_SLASHES)) . "', " .
            "`fulfillment` = '" . $this->db->escape((string) json_encode($session['fulfillment'] ?? null, JSON_UNESCAPED_SLASHES)) . "', " .
            "`payment` = '" . $this->db->escape((string) json_encode($session['payment'] ?? null, JSON_UNESCAPED_SLASHES)) . "', " .
            "`totals` = '" . $this->db->escape((string) json_encode($session['totals'] ?? [], JSON_UNESCAPED_SLASHES)) . "', " .
            "`messages` = '" . $this->db->escape((string) json_encode($session['messages'] ?? [], JSON_UNESCAPED_SLASHES)) . "', " .
            "`updated_at` = {$now} " .
            "WHERE `id` = '" . $this->db->escape((string) $session['id']) . "'"
        );
    }

    private function shape(array $session): array
    {
        $body = [
            'id'         => $session['id'],
            'status'     => $session['status'],
            'currency'   => $session['currency'] ?? $this->currency(),
            'line_items' => $session['line_items'] ?? [],
            'totals'     => $session['totals'] ?? [],
            'messages'   => $session['messages'] ?? [],
            'expires_at' => gmdate('c', (int) $session['expires_at']),
        ];
        if (!empty($session['buyer'])) {
            $body['buyer'] = $session['buyer'];
        }
        if (!empty($session['fulfillment'])) {
            $body['fulfillment'] = $session['fulfillment'];
        }
        if (!empty($session['payment'])) {
            $body['payment'] = $session['payment'];
        }
        return $body;
    }

    private function buildFulfillment(array $session, array $input): array
    {
        $methodsIn = $input['methods'] ?? [];
        $destinations = $methodsIn[0]['destinations'] ?? [];
        $selectedDest = $methodsIn[0]['selected_destination_id'] ?? ($destinations[0]['id'] ?? 'dest_1');
        $selectedOption = $methodsIn[0]['groups'][0]['selected_option_id'] ?? null;

        $options = $this->shippingOptions($session, $destinations[0] ?? []);
        if ($selectedOption === null && !empty($options)) {
            $selectedOption = null; // still require explicit selection
        }

        return [
            'methods' => [[
                'id' => 'fm_1',
                'type' => 'shipping',
                'line_item_ids' => array_map(static fn($li) => $li['id'], $session['line_items']),
                'selected_destination_id' => $selectedDest,
                'destinations' => array_map(
                    static fn($d, $i) => Response::address($d, $d['id'] ?? ('dest_' . ($i + 1))),
                    $destinations,
                    array_keys($destinations)
                ),
                'groups' => [[
                    'id' => 'fg_1',
                    'line_item_ids' => array_map(static fn($li) => $li['id'], $session['line_items']),
                    'selected_option_id' => $selectedOption,
                    'options' => $options,
                ]],
            ]],
        ];
    }

    private function shippingOptions(array $session, array $dest): array
    {
        // Simple policy: free shipping > $50, flat $5.99 standard, $12.99 express.
        $subtotal = 0;
        foreach ($session['line_items'] as $li) {
            foreach ($li['totals'] as $t) {
                if ($t['type'] === 'subtotal') {
                    $subtotal += (int) $t['amount'];
                }
            }
        }
        $options = [
            ['id' => 'fo_standard', 'title' => 'Standard',
                'description' => '5-7 business days',
                'totals' => [['type' => 'shipping', 'amount' => $subtotal >= 5000 ? 0 : 599]]],
            ['id' => 'fo_express', 'title' => 'Express',
                'description' => '2-3 business days',
                'totals' => [['type' => 'shipping', 'amount' => 1299]]],
        ];
        return $options;
    }

    private function recomputeTotals(array $session): array
    {
        $subtotal = 0;
        foreach ($session['line_items'] as $li) {
            foreach ($li['totals'] as $t) {
                if ($t['type'] === 'subtotal') {
                    $subtotal += (int) $t['amount'];
                }
            }
        }
        $shipping = 0;
        $options = $session['fulfillment']['methods'][0]['groups'][0]['options'] ?? [];
        $selected = $session['fulfillment']['methods'][0]['groups'][0]['selected_option_id'] ?? null;
        foreach ($options as $opt) {
            if ($opt['id'] === $selected) {
                foreach ($opt['totals'] as $t) {
                    if ($t['type'] === 'shipping') {
                        $shipping = (int) $t['amount'];
                    }
                }
            }
        }
        // Naive tax: 8% of subtotal + shipping. Real implementation would call OpenCart's tax engine.
        $tax = (int) round(($subtotal + $shipping) * 0.08);
        return Response::totals([
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'tax'      => $tax,
            'total'    => $subtotal + $shipping + $tax,
        ]);
    }

    private function createOpencartOrder(array $session): int
    {
        $now = date('Y-m-d H:i:s');
        $buyer = $session['buyer'] ?? [];
        $dest = $session['fulfillment']['methods'][0]['destinations'][0] ?? [];
        $currency = $session['currency'] ?? $this->currency();

        $this->db->query("INSERT INTO `" . DB_PREFIX . "order` SET " .
            "`store_id` = 0, " .
            "`customer_id` = 0, " .
            "`customer_group_id` = 1, " .
            "`firstname` = '" . $this->db->escape((string) ($buyer['first_name'] ?? '')) . "', " .
            "`lastname` = '" . $this->db->escape((string) ($buyer['last_name'] ?? '')) . "', " .
            "`email` = '" . $this->db->escape((string) ($buyer['email'] ?? '')) . "', " .
            "`telephone` = '" . $this->db->escape((string) ($buyer['phone'] ?? '')) . "', " .
            "`shipping_firstname` = '" . $this->db->escape((string) ($buyer['first_name'] ?? '')) . "', " .
            "`shipping_lastname`  = '" . $this->db->escape((string) ($buyer['last_name'] ?? '')) . "', " .
            "`shipping_address_1` = '" . $this->db->escape((string) ($dest['street_address'] ?? '')) . "', " .
            "`shipping_city`      = '" . $this->db->escape((string) ($dest['address_locality'] ?? '')) . "', " .
            "`shipping_postcode`  = '" . $this->db->escape((string) ($dest['postal_code'] ?? '')) . "', " .
            "`shipping_zone`      = '" . $this->db->escape((string) ($dest['address_region'] ?? '')) . "', " .
            "`shipping_country`   = '" . $this->db->escape((string) ($dest['address_country'] ?? '')) . "', " .
            "`payment_method` = 'UCP Deferred Payment', " .
            "`shipping_method` = 'UCP Shipping', " .
            "`currency_code` = '" . $this->db->escape($currency) . "', " .
            "`currency_value` = 1.0, " .
            "`total` = " . ($this->totalAmount($session) / 100.0) . ", " .
            "`order_status_id` = 1, " .
            "`ip` = '" . $this->db->escape((string) ($_SERVER['REMOTE_ADDR'] ?? '')) . "', " .
            "`date_added` = '" . $now . "', " .
            "`date_modified` = '" . $now . "'");
        $orderId = (int) $this->db->getLastId();

        foreach ($session['line_items'] as $li) {
            $itemPrice = 0;
            foreach ($li['totals'] as $t) {
                if ($t['type'] === 'subtotal') {
                    $itemPrice = (int) $t['amount'] / 100.0 / (int) $li['quantity'];
                }
            }
            $this->db->query(
                "INSERT INTO `" . DB_PREFIX . "order_product` SET " .
                "`order_id` = {$orderId}, " .
                "`product_id` = " . (int) $li['item']['id'] . ", " .
                "`name` = '" . $this->db->escape((string) $li['item']['title']) . "', " .
                "`model` = '', " .
                "`quantity` = " . (int) $li['quantity'] . ", " .
                "`price` = " . $itemPrice . ", " .
                "`total` = " . ($itemPrice * (int) $li['quantity']) . ", " .
                "`tax` = 0, `reward` = 0"
            );
        }

        return $orderId;
    }

    private function totalAmount(array $session): int
    {
        foreach ($session['totals'] ?? [] as $t) {
            if ($t['type'] === 'total') {
                return (int) $t['amount'];
            }
        }
        return 0;
    }

    private function paymentUrl(int $orderId): string
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $base = (string) ($config->get('config_url') ?? $config->get('config_ssl') ?? '');
        return rtrim($base, '/') . '/index.php?route=checkout/success&order_id=' . $orderId;
    }

    private function loadProduct(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }
        $r = $this->db->query(
            "SELECT p.`product_id`, p.`price`, p.`quantity`, p.`subtract`, p.`image`, pd.`name` " .
            "FROM `" . DB_PREFIX . "product` p " .
            "LEFT JOIN `" . DB_PREFIX . "product_description` pd " .
            "  ON pd.`product_id` = p.`product_id` AND pd.`language_id` = " . $this->languageId() . " " .
            "WHERE p.`product_id` = {$productId} AND p.`status` = 1 LIMIT 1"
        );
        return $r->num_rows > 0 ? $r->row : null;
    }

    private function productImageUrl(string $image): string
    {
        if ($image === '') {
            return '';
        }
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $base = (string) ($config->get('config_url') ?? $config->get('config_ssl') ?? '');
        return rtrim($base, '/') . '/image/' . ltrim($image, '/');
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
