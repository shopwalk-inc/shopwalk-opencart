<?php
/**
 * Shopwalk\Opencart\OauthClients — CRUD for UCP agent client registrations.
 *
 * Clients are either self-registered through the dynamic client registration
 * endpoint (RFC 7591) or created from the admin dashboard.
 */

declare(strict_types=1);

namespace Shopwalk\Opencart;

final class OauthClients
{
    private \DB $db;

    public function __construct(\Registry $registry)
    {
        $this->db = $registry->get('db');
    }

    /**
     * @param array{name:string,redirect_uris:array<int,string>,profile_url?:string,scopes?:string} $params
     * @return array{client_id:string,client_secret:string,client_secret_hash:string}
     */
    public function register(array $params): array
    {
        $clientId = 'ucp_' . bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(24));
        $hash = password_hash($secret, PASSWORD_BCRYPT);
        $scopes = (string) ($params['scopes'] ?? 'ucp:scopes:checkout_session ucp:scopes:orders');
        $redirects = json_encode(array_values($params['redirect_uris'] ?? []), JSON_UNESCAPED_SLASHES);
        $now = time();

        $this->db->query(
            "INSERT INTO `" . SHOPWALK_UCP_TABLE_PREFIX . "oauth_clients` " .
            "(`client_id`, `client_secret_hash`, `name`, `profile_url`, `redirect_uris`, `scopes`, `created_at`) " .
            "VALUES (" .
            "'" . $this->db->escape($clientId) . "', " .
            "'" . $this->db->escape($hash) . "', " .
            "'" . $this->db->escape((string) $params['name']) . "', " .
            "'" . $this->db->escape((string) ($params['profile_url'] ?? '')) . "', " .
            "'" . $this->db->escape((string) $redirects) . "', " .
            "'" . $this->db->escape($scopes) . "', " .
            "{$now})"
        );

        return [
            'client_id'          => $clientId,
            'client_secret'      => $secret,
            'client_secret_hash' => $hash,
        ];
    }

    public function findById(string $clientId): ?array
    {
        $r = $this->db->query(
            "SELECT * FROM `" . SHOPWALK_UCP_TABLE_PREFIX . "oauth_clients` " .
            "WHERE `client_id` = '" . $this->db->escape($clientId) . "' AND `revoked_at` IS NULL LIMIT 1"
        );
        if ($r->num_rows === 0) {
            return null;
        }
        $row = $r->row;
        $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
        return $row;
    }

    public function verifySecret(string $clientId, string $clientSecret): ?array
    {
        $client = $this->findById($clientId);
        if ($client === null) {
            return null;
        }
        return password_verify($clientSecret, (string) $client['client_secret_hash']) ? $client : null;
    }

    public function all(): array
    {
        $r = $this->db->query(
            "SELECT * FROM `" . SHOPWALK_UCP_TABLE_PREFIX . "oauth_clients` ORDER BY `created_at` DESC"
        );
        return $r->rows;
    }

    public function revoke(string $clientId): void
    {
        $now = time();
        $this->db->query(
            "UPDATE `" . SHOPWALK_UCP_TABLE_PREFIX . "oauth_clients` " .
            "SET `revoked_at` = {$now} WHERE `client_id` = '" . $this->db->escape($clientId) . "'"
        );
    }
}
