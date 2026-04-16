<?php
/**
 * Shopwalk\Ucp\Idempotency — RFC-style idempotency key enforcement.
 *
 * Callers use `remember($key, $client, $body, $fn)`:
 *   - Same key + same body → cached response is returned (no re-execution).
 *   - Same key + different body → returns a 409 conflict response.
 *   - New key or expired key → $fn is executed and the result is cached.
 */

declare(strict_types=1);

namespace Shopwalk\Ucp;

final class Idempotency
{
    private \DB $db;

    public function __construct(\Registry $registry)
    {
        $this->db = $registry->get('db');
    }

    /**
     * @param callable():array{body:array<string,mixed>,status:int} $fn
     * @return array{body:array<string,mixed>,status:int,from_cache:bool}
     */
    public function remember(?string $key, string $clientId, string $rawBody, callable $fn): array
    {
        if ($key === null || $key === '') {
            $result = $fn();
            return ['body' => $result['body'], 'status' => $result['status'] ?? 200, 'from_cache' => false];
        }

        $p = SHOPWALK_UCP_TABLE_PREFIX;
        $hash = hash('sha256', $rawBody);
        $now = time();

        $row = $this->db->query(
            "SELECT `request_hash`, `response_body`, `response_status` " .
            "FROM `{$p}idempotency` " .
            "WHERE `idempotency_key` = '" . $this->db->escape($key) . "' " .
            "  AND `client_id` = '" . $this->db->escape($clientId) . "' " .
            "  AND `expires_at` > {$now} LIMIT 1"
        );

        if ($row->num_rows > 0) {
            $cached = $row->row;
            if ($cached['request_hash'] === $hash) {
                return [
                    'body'       => (array) json_decode((string) $cached['response_body'], true),
                    'status'     => (int) $cached['response_status'],
                    'from_cache' => true,
                ];
            }
            return [
                'body' => Response::error(
                    'idempotency_conflict',
                    'Idempotency key already used with a different request body.',
                    'unrecoverable'
                ),
                'status'     => 409,
                'from_cache' => false,
            ];
        }

        $result = $fn();
        $body = $result['body'] ?? [];
        $status = $result['status'] ?? 200;
        $expires = $now + SHOPWALK_UCP_IDEMPOTENCY_TTL;

        $this->db->query(
            "INSERT IGNORE INTO `{$p}idempotency` " .
            "(`idempotency_key`, `client_id`, `request_hash`, `response_body`, `response_status`, `created_at`, `expires_at`) " .
            "VALUES (" .
            "'" . $this->db->escape($key) . "', " .
            "'" . $this->db->escape($clientId) . "', " .
            "'" . $this->db->escape($hash) . "', " .
            "'" . $this->db->escape((string) json_encode($body, JSON_UNESCAPED_SLASHES)) . "', " .
            "{$status}, {$now}, {$expires})"
        );

        return ['body' => $body, 'status' => $status, 'from_cache' => false];
    }
}
