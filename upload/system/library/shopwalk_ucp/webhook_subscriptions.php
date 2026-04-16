<?php
/**
 * Shopwalk\Ucp\WebhookSubscriptions — manage agent webhook subscriptions.
 *
 * UCP agents subscribe to order lifecycle events by registering a callback
 * URL and an event filter. The plugin fires webhooks to every matching
 * subscription whenever an OpenCart order changes state.
 */

declare(strict_types=1);

namespace Shopwalk\Ucp;

final class WebhookSubscriptions
{
    private \DB $db;

    public function __construct(\Registry $registry)
    {
        $this->db = $registry->get('db');
    }

    /**
     * @param array{client_id:string,callback_url:string,events?:string,secret?:string} $params
     */
    public function create(array $params): array
    {
        $id = 'whs_' . bin2hex(random_bytes(8));
        $now = time();
        $this->db->query(
            "INSERT INTO `" . SHOPWALK_UCP_TABLE_PREFIX . "webhook_subscriptions` " .
            "(`id`,`client_id`,`callback_url`,`events`,`secret`,`created_at`) VALUES (" .
            "'" . $this->db->escape($id) . "', " .
            "'" . $this->db->escape((string) $params['client_id']) . "', " .
            "'" . $this->db->escape((string) $params['callback_url']) . "', " .
            "'" . $this->db->escape((string) ($params['events'] ?? 'order.*')) . "', " .
            "'" . $this->db->escape((string) ($params['secret'] ?? '')) . "', " .
            "{$now})"
        );
        return [
            'id'           => $id,
            'client_id'    => $params['client_id'],
            'callback_url' => $params['callback_url'],
            'events'       => $params['events'] ?? 'order.*',
            'created_at'   => $now,
        ];
    }

    public function delete(string $id): void
    {
        $this->db->query(
            "UPDATE `" . SHOPWALK_UCP_TABLE_PREFIX . "webhook_subscriptions` " .
            "SET `disabled_at` = " . time() . " " .
            "WHERE `id` = '" . $this->db->escape($id) . "'"
        );
    }

    public function listAll(): array
    {
        return $this->db->query(
            "SELECT * FROM `" . SHOPWALK_UCP_TABLE_PREFIX . "webhook_subscriptions` " .
            "WHERE `disabled_at` IS NULL ORDER BY `created_at` DESC"
        )->rows;
    }

    public function matching(string $eventType): array
    {
        $rows = $this->listAll();
        return array_values(array_filter($rows, static function (array $row) use ($eventType): bool {
            $patterns = preg_split('/[\s,]+/', (string) $row['events']) ?: [];
            foreach ($patterns as $pattern) {
                if ($pattern === '' || $pattern === '*' || $pattern === $eventType) {
                    return true;
                }
                $regex = '/^' . str_replace(['\\*', '\\.'], ['.*', '\\.'], preg_quote($pattern, '/')) . '$/';
                if (preg_match($regex, $eventType)) {
                    return true;
                }
            }
            return false;
        }));
    }
}
