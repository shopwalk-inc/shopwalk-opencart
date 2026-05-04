<?php
/**
 * Shopwalk\Opencart\OauthServer — OAuth 2.0 + PKCE S256 authorization server.
 *
 * Endpoints (routed under /index.php?route=extension/shopwalk_ucp/oauth/...):
 *   GET  /authorize  — login screen that issues a code bound to the customer
 *   POST /token      — exchanges code for access_token / refresh_token
 *   POST /revoke     — revokes a token
 *   GET  /userinfo   — returns the bearer's customer identity
 *   POST /register   — RFC 7591 dynamic client registration
 *
 * Tokens and codes live in {prefix}ucp_oauth_tokens. Access tokens are opaque
 * (not JWTs) — introspection happens locally via the table.
 */

declare(strict_types=1);

namespace Shopwalk\Opencart;

final class OauthServer
{
    private \Registry $registry;
    private \DB $db;
    private OauthClients $clients;

    public const ACCESS_TTL  = 3600;
    public const REFRESH_TTL = 2592000;
    public const CODE_TTL    = 600;

    public function __construct(\Registry $registry)
    {
        $this->registry = $registry;
        $this->db = $registry->get('db');
        $this->clients = new OauthClients($registry);
    }

    public function authorize(array $query, int $customerId): array
    {
        foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method'] as $req) {
            if (empty($query[$req])) {
                return ['status' => 400, 'body' => Response::error('invalid_request', "Missing {$req}")];
            }
        }
        if ($query['response_type'] !== 'code') {
            return ['status' => 400, 'body' => Response::error('unsupported_response_type', 'Only code flow is supported')];
        }
        if ($query['code_challenge_method'] !== 'S256') {
            return ['status' => 400, 'body' => Response::error('invalid_request', 'code_challenge_method must be S256')];
        }
        $client = $this->clients->findById((string) $query['client_id']);
        if ($client === null) {
            return ['status' => 400, 'body' => Response::error('invalid_client', 'Unknown client')];
        }
        if (!in_array($query['redirect_uri'], $client['redirect_uris'], true)) {
            return ['status' => 400, 'body' => Response::error('invalid_redirect_uri', 'redirect_uri not registered')];
        }
        if ($customerId <= 0) {
            return ['status' => 401, 'body' => Response::error('login_required', 'Customer must be signed in')];
        }
        $code = 'code_' . bin2hex(random_bytes(24));
        $scopes = (string) ($query['scope'] ?? $client['scopes']);
        $this->insertToken([
            'token'          => $code,
            'type'           => 'code',
            'client_id'      => $client['client_id'],
            'customer_id'    => $customerId,
            'scopes'         => $scopes,
            'code_challenge' => (string) $query['code_challenge'],
            'redirect_uri'   => (string) $query['redirect_uri'],
            'ttl'            => self::CODE_TTL,
        ]);
        $sep = str_contains((string) $query['redirect_uri'], '?') ? '&' : '?';
        $redirect = $query['redirect_uri'] . $sep . 'code=' . urlencode($code);
        if (!empty($query['state'])) {
            $redirect .= '&state=' . urlencode((string) $query['state']);
        }
        return ['status' => 302, 'redirect' => $redirect];
    }

    public function token(array $body): array
    {
        $grant = (string) ($body['grant_type'] ?? '');
        return match ($grant) {
            'authorization_code' => $this->grantAuthorizationCode($body),
            'refresh_token'      => $this->grantRefresh($body),
            default              => ['status' => 400, 'body' => Response::error('unsupported_grant_type', $grant)],
        };
    }

    private function grantAuthorizationCode(array $body): array
    {
        foreach (['code', 'client_id', 'client_secret', 'redirect_uri', 'code_verifier'] as $req) {
            if (empty($body[$req])) {
                return ['status' => 400, 'body' => Response::error('invalid_request', "Missing {$req}")];
            }
        }
        $client = $this->clients->verifySecret((string) $body['client_id'], (string) $body['client_secret']);
        if ($client === null) {
            return ['status' => 401, 'body' => Response::error('invalid_client', 'Bad client credentials')];
        }
        $row = $this->findToken((string) $body['code'], 'code');
        if ($row === null || $row['client_id'] !== $client['client_id']) {
            return ['status' => 400, 'body' => Response::error('invalid_grant', 'Unknown or expired code')];
        }
        if ($row['redirect_uri'] !== $body['redirect_uri']) {
            return ['status' => 400, 'body' => Response::error('invalid_grant', 'redirect_uri mismatch')];
        }
        $expected = rtrim(strtr(base64_encode(hash('sha256', (string) $body['code_verifier'], true)), '+/', '-_'), '=');
        if (!hash_equals($expected, (string) $row['code_challenge'])) {
            return ['status' => 400, 'body' => Response::error('invalid_grant', 'PKCE verification failed')];
        }
        $this->revokeToken((string) $row['token']);
        return $this->issueTokenPair($client['client_id'], (int) $row['customer_id'], (string) $row['scopes']);
    }

    private function grantRefresh(array $body): array
    {
        if (empty($body['refresh_token']) || empty($body['client_id']) || empty($body['client_secret'])) {
            return ['status' => 400, 'body' => Response::error('invalid_request', 'Missing refresh_token or credentials')];
        }
        $client = $this->clients->verifySecret((string) $body['client_id'], (string) $body['client_secret']);
        if ($client === null) {
            return ['status' => 401, 'body' => Response::error('invalid_client', 'Bad client credentials')];
        }
        $row = $this->findTokenByRefresh((string) $body['refresh_token']);
        if ($row === null || $row['client_id'] !== $client['client_id']) {
            return ['status' => 400, 'body' => Response::error('invalid_grant', 'Unknown refresh token')];
        }
        $this->revokeToken((string) $row['token']);
        return $this->issueTokenPair($client['client_id'], (int) $row['customer_id'], (string) $row['scopes']);
    }

    public function revoke(array $body): array
    {
        if (empty($body['token'])) {
            return ['status' => 400, 'body' => Response::error('invalid_request', 'token required')];
        }
        $this->revokeToken((string) $body['token']);
        $this->db->query(
            "UPDATE `" . SHOPWALK_UCP_TABLE_PREFIX . "oauth_tokens` " .
            "SET `revoked_at` = " . time() . " " .
            "WHERE `refresh_token` = '" . $this->db->escape((string) $body['token']) . "'"
        );
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function userinfo(?string $bearer): array
    {
        $claims = $this->introspect($bearer);
        if ($claims === null) {
            return ['status' => 401, 'body' => Response::error('invalid_token', 'Bearer token invalid or expired')];
        }
        $customerId = (int) $claims['customer_id'];
        $customer = $this->loadCustomer($customerId);
        return ['status' => 200, 'body' => Response::ok([
            'sub'        => (string) $customerId,
            'email'      => $customer['email'] ?? '',
            'given_name' => $customer['firstname'] ?? '',
            'family_name' => $customer['lastname'] ?? '',
            'scopes'     => explode(' ', (string) $claims['scopes']),
        ])];
    }

    /**
     * Introspect a bearer token. Returns null if invalid/expired/revoked.
     * @return array<string,mixed>|null
     */
    public function introspect(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $token = preg_replace('/^Bearer\s+/i', '', $token);
        return $this->findToken((string) $token, 'access');
    }

    private function issueTokenPair(string $clientId, int $customerId, string $scopes): array
    {
        $access = 'at_' . bin2hex(random_bytes(24));
        $refresh = 'rt_' . bin2hex(random_bytes(24));
        $this->insertToken([
            'token'         => $access,
            'type'          => 'access',
            'client_id'     => $clientId,
            'customer_id'   => $customerId,
            'scopes'        => $scopes,
            'refresh_token' => $refresh,
            'ttl'           => self::ACCESS_TTL,
        ]);
        $this->insertToken([
            'token'         => $refresh,
            'type'          => 'refresh',
            'client_id'     => $clientId,
            'customer_id'   => $customerId,
            'scopes'        => $scopes,
            'refresh_token' => $refresh,
            'ttl'           => self::REFRESH_TTL,
        ]);
        return ['status' => 200, 'body' => [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope'         => $scopes,
        ]];
    }

    private function insertToken(array $t): void
    {
        $now = time();
        $expires = $now + (int) $t['ttl'];
        $this->db->query(
            "INSERT INTO `" . SHOPWALK_UCP_TABLE_PREFIX . "oauth_tokens` " .
            "(`token`, `type`, `client_id`, `customer_id`, `scopes`, `refresh_token`, `code_challenge`, `redirect_uri`, `created_at`, `expires_at`) VALUES (" .
            "'" . $this->db->escape((string) $t['token']) . "', " .
            "'" . $this->db->escape((string) $t['type']) . "', " .
            "'" . $this->db->escape((string) $t['client_id']) . "', " .
            ((int) ($t['customer_id'] ?? 0)) . ", " .
            "'" . $this->db->escape((string) $t['scopes']) . "', " .
            "'" . $this->db->escape((string) ($t['refresh_token'] ?? '')) . "', " .
            "'" . $this->db->escape((string) ($t['code_challenge'] ?? '')) . "', " .
            "'" . $this->db->escape((string) ($t['redirect_uri'] ?? '')) . "', " .
            "{$now}, {$expires})"
        );
    }

    private function findToken(string $token, string $type): ?array
    {
        $r = $this->db->query(
            "SELECT * FROM `" . SHOPWALK_UCP_TABLE_PREFIX . "oauth_tokens` " .
            "WHERE `token` = '" . $this->db->escape($token) . "' " .
            "  AND `type` = '" . $this->db->escape($type) . "' " .
            "  AND `revoked_at` IS NULL AND `expires_at` > " . time() . " LIMIT 1"
        );
        return $r->num_rows > 0 ? $r->row : null;
    }

    private function findTokenByRefresh(string $refresh): ?array
    {
        $r = $this->db->query(
            "SELECT * FROM `" . SHOPWALK_UCP_TABLE_PREFIX . "oauth_tokens` " .
            "WHERE `refresh_token` = '" . $this->db->escape($refresh) . "' " .
            "  AND `type` = 'refresh' AND `revoked_at` IS NULL AND `expires_at` > " . time() . " LIMIT 1"
        );
        return $r->num_rows > 0 ? $r->row : null;
    }

    private function revokeToken(string $token): void
    {
        $this->db->query(
            "UPDATE `" . SHOPWALK_UCP_TABLE_PREFIX . "oauth_tokens` " .
            "SET `revoked_at` = " . time() . " " .
            "WHERE `token` = '" . $this->db->escape($token) . "'"
        );
    }

    private function loadCustomer(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }
        $r = $this->db->query(
            "SELECT `email`, `firstname`, `lastname` FROM `" . DB_PREFIX . "customer` " .
            "WHERE `customer_id` = {$customerId} LIMIT 1"
        );
        return $r->num_rows > 0 ? $r->row : [];
    }
}
