<?php
/**
 * Shopwalk\Opencart\WebhookDelivery — signs and sends outbound order webhooks.
 *
 * Triggered from the OpenCart `admin/model/sale/order/addOrderHistory/after`
 * event (wired up via startup event registration). On each order status
 * change we build the full UCP order entity, sign per RFC 9421, and POST
 * it to every matching subscription callback_url.
 *
 * Failures are logged into {prefix}ucp_webhook_deliveries with exponential
 * retry backoff (5s, 30s, 5m, 1h) — a cron job picks up the pending rows.
 */

declare(strict_types=1);

namespace Shopwalk\Opencart;

final class WebhookDelivery
{
    private \Registry $registry;
    private \DB $db;
    private Signing $signing;
    private Orders $orders;
    private WebhookSubscriptions $subs;

    private const BACKOFF = [5, 30, 300, 3600];

    public function __construct(\Registry $registry)
    {
        $this->registry = $registry;
        $this->db = $registry->get('db');
        $this->signing = new Signing($registry);
        $this->orders = new Orders($registry);
        $this->subs = new WebhookSubscriptions($registry);
    }

    public function onOrderStatusChanged(int $orderId, int $fromStatusId, int $toStatusId): void
    {
        $entity = $this->orders->fetchOrder($orderId)['body'] ?? null;
        if ($entity === null) {
            return;
        }
        $eventType = $this->statusToEventType($toStatusId);
        $event = [
            'id'            => 'evt_' . bin2hex(random_bytes(8)),
            'type'          => $eventType,
            'occurred_at'   => gmdate('c'),
            'order'         => $entity,
            'from_status_id' => $fromStatusId,
            'to_status_id'   => $toStatusId,
        ];

        foreach ($this->subs->matching($eventType) as $sub) {
            $this->enqueue($sub, $eventType, $event);
        }
    }

    /**
     * Attempt to deliver every queued webhook that is due. Invoked from cron
     * (`php upload/admin/cli/webhook-worker.php`) or synchronously at the end
     * of a request when queue is short.
     */
    public function flushPending(int $batch = 25): int
    {
        $p = SHOPWALK_UCP_TABLE_PREFIX;
        $now = time();
        $rows = $this->db->query(
            "SELECT * FROM `{$p}webhook_deliveries` " .
            "WHERE `delivered_at` IS NULL AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= {$now}) " .
            "ORDER BY `created_at` ASC LIMIT {$batch}"
        )->rows;
        $count = 0;
        foreach ($rows as $row) {
            if ($this->send($row)) {
                $count++;
            }
        }
        return $count;
    }

    private function enqueue(array $sub, string $eventType, array $event): void
    {
        $id = 'dlv_' . bin2hex(random_bytes(8));
        $now = time();
        $this->db->query(
            "INSERT INTO `" . SHOPWALK_UCP_TABLE_PREFIX . "webhook_deliveries` " .
            "(`id`,`subscription_id`,`event_type`,`payload`,`next_attempt_at`,`created_at`) VALUES (" .
            "'" . $this->db->escape($id) . "', " .
            "'" . $this->db->escape((string) $sub['id']) . "', " .
            "'" . $this->db->escape($eventType) . "', " .
            "'" . $this->db->escape((string) json_encode($event, JSON_UNESCAPED_SLASHES)) . "', " .
            "{$now}, {$now})"
        );
    }

    private function send(array $row): bool
    {
        $subQ = $this->db->query(
            "SELECT * FROM `" . SHOPWALK_UCP_TABLE_PREFIX . "webhook_subscriptions` " .
            "WHERE `id` = '" . $this->db->escape((string) $row['subscription_id']) . "' LIMIT 1"
        );
        if ($subQ->num_rows === 0) {
            return false;
        }
        $sub = $subQ->row;
        $body = (string) $row['payload'];
        $headers = $this->signing->webhookHeaders($body, $this->profileUrl());
        $headers['Content-Type'] = 'application/json';
        if (!empty($sub['secret'])) {
            $headers['X-Subscription-Secret'] = (string) $sub['secret'];
        }

        $ch = curl_init((string) $sub['callback_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => array_map(static fn($k, $v) => $k . ': ' . $v, array_keys($headers), $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $respBody = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $attempts = (int) $row['attempts'] + 1;
        $delivered = $status >= 200 && $status < 300;
        $now = time();
        $nextAttempt = $delivered ? null : ($now + (self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)] ?? 3600));

        $this->db->query(
            "UPDATE `" . SHOPWALK_UCP_TABLE_PREFIX . "webhook_deliveries` SET " .
            "`http_status` = {$status}, " .
            "`response_body` = '" . $this->db->escape(substr($respBody, 0, 2048)) . "', " .
            "`attempts` = {$attempts}, " .
            "`delivered_at` = " . ($delivered ? $now : 'NULL') . ", " .
            "`next_attempt_at` = " . ($nextAttempt ?? 'NULL') . " " .
            "WHERE `id` = '" . $this->db->escape((string) $row['id']) . "'"
        );
        return $delivered;
    }

    private function statusToEventType(int $statusId): string
    {
        return match ($statusId) {
            1  => 'order.pending',
            2  => 'order.processing',
            3  => 'order.shipped',
            5  => 'order.delivered',
            7  => 'order.canceled',
            11 => 'order.refunded',
            default => 'order.status_changed',
        };
    }

    private function profileUrl(): string
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $base = (string) ($config->get('config_url') ?? $config->get('config_ssl') ?? '');
        return rtrim($base, '/') . '/.well-known/ucp';
    }
}
