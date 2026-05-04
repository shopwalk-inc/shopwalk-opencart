<?php
/**
 * Shopwalk\Opencart\Signing — RFC 9421 HTTP Message Signatures + detached JWTs.
 *
 * Outbound webhooks are signed per RFC 9421 with SHA-256 HMAC over
 * (content-digest, webhook-id, webhook-timestamp). The same keypair is
 * published in /.well-known/ucp so agents can verify.
 *
 * Inbound agent Request-Signature headers are parsed as detached JWTs
 * (RFC 7797) and verified against the agent's published JWKS.
 */

declare(strict_types=1);

namespace Shopwalk\Opencart;

final class Signing
{
    private \Registry $registry;

    public function __construct(\Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Return the store signing keypair, generating it on first access.
     *
     * @return array{kid:string,secret:string,created_at:int}
     */
    public function keypair(): array
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $existing = $config->get('shopwalk_ucp_signing_key');
        if (is_array($existing) && !empty($existing['kid']) && !empty($existing['secret'])) {
            return $existing;
        }
        $kid = 'sw-' . bin2hex(random_bytes(6));
        $secret = base64_encode(random_bytes(32));
        $pair = ['kid' => $kid, 'secret' => $secret, 'created_at' => time()];
        $this->persist('shopwalk_ucp_signing_key', $pair);
        return $pair;
    }

    public function publicJwk(): array
    {
        $pair = $this->keypair();
        return [
            'kid' => $pair['kid'],
            'kty' => 'oct',
            'alg' => 'HS256',
            'use' => 'sig',
        ];
    }

    public function fingerprint(): string
    {
        $pair = $this->keypair();
        return substr(hash('sha256', $pair['secret']), 0, 16);
    }

    /**
     * Build the four headers required on outbound UCP webhooks:
     * Content-Digest, Webhook-Timestamp, Webhook-Id, Signature-Input, Signature.
     *
     * @return array<string,string>
     */
    public function webhookHeaders(string $body, string $profileUrl): array
    {
        $pair = $this->keypair();
        $timestamp = (string) time();
        $webhookId = 'evt_' . bin2hex(random_bytes(12));
        $digest = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
        $sigInput = sprintf(
            'sig1=("content-digest" "webhook-id" "webhook-timestamp");keyid="%s";alg="hmac-sha256";created=%s',
            $pair['kid'],
            $timestamp
        );
        $signatureBase = sprintf(
            "\"content-digest\": %s\n\"webhook-id\": %s\n\"webhook-timestamp\": %s\n\"@signature-params\": %s",
            $digest,
            $webhookId,
            $timestamp,
            substr($sigInput, strlen('sig1='))
        );
        $mac = hash_hmac('sha256', $signatureBase, base64_decode($pair['secret']), true);
        $signature = 'sig1=:' . base64_encode($mac) . ':';
        return [
            'Content-Digest'    => $digest,
            'Webhook-Id'        => $webhookId,
            'Webhook-Timestamp' => $timestamp,
            'UCP-Agent'         => sprintf('profile="%s"', $profileUrl),
            'Signature-Input'   => $sigInput,
            'Signature'         => $signature,
        ];
    }

    /**
     * Verify a detached JWT Request-Signature header against the body.
     * Accepts HS256 (shared secret at setting `shopwalk_ucp_agent_secret`) for Phase 1.
     * Returns true when signature is valid OR when no shared secret is configured
     * (open mode — agents can still authenticate via bearer token alone).
     */
    public function verifyRequestSignature(string $body, ?string $headerValue): bool
    {
        if ($headerValue === null || $headerValue === '') {
            return true;
        }
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $secret = (string) $config->get('shopwalk_ucp_agent_secret');
        if ($secret === '') {
            return true;
        }
        $parts = explode('.', $headerValue);
        if (count($parts) !== 3) {
            return false;
        }
        [$h64, $p64, $s64] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $h64 . '.' . $p64, $secret, true));
        if (!hash_equals($expected, $s64)) {
            return false;
        }
        $payload = json_decode((string) $this->base64UrlDecode($p64), true);
        if (!is_array($payload)) {
            return false;
        }
        $bodyHash = hash('sha256', $body);
        return isset($payload['body_sha256']) && hash_equals((string) $payload['body_sha256'], $bodyHash);
    }

    public function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public function base64UrlDecode(string $str): string
    {
        $pad = strlen($str) % 4;
        if ($pad) {
            $str .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode(strtr($str, '-_', '+/'));
    }

    private function persist(string $key, array $value): void
    {
        $db = $this->registry->get('db');
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        $db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `code` = 'shopwalk_ucp' AND `key` = '" . $db->escape($key) . "'");
        $db->query(
            "INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = 0, `code` = 'shopwalk_ucp', " .
            "`key` = '" . $db->escape($key) . "', " .
            "`value` = '" . $db->escape($json) . "', " .
            "`serialized` = 1"
        );
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $config->set($key, $value);
    }
}
