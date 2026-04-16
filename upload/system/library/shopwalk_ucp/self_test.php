<?php
/**
 * Shopwalk\Ucp\SelfTest — diagnostic checks surfaced in the admin dashboard
 * and available via `php upload/admin/cli/self-test.php`.
 *
 * Each check returns ['name' => ..., 'status' => ok|warn|error, 'detail' => ...].
 */

declare(strict_types=1);

namespace Shopwalk\Ucp;

final class SelfTest
{
    private \Registry $registry;

    public function __construct(\Registry $registry)
    {
        $this->registry = $registry;
    }

    /** @return array<int,array{name:string,status:string,detail:string}> */
    public function run(): array
    {
        return [
            $this->checkTables(),
            $this->checkSigningKeys(),
            $this->checkWellKnown(),
            $this->checkPhpVersion(),
            $this->checkHttps(),
        ];
    }

    private function checkTables(): array
    {
        /** @var \DB $db */
        $db = $this->registry->get('db');
        $p = SHOPWALK_UCP_TABLE_PREFIX;
        $missing = [];
        foreach (
            ['checkout_sessions','oauth_clients','oauth_tokens','webhook_subscriptions',
             'webhook_deliveries','idempotency','order_events'] as $t
        ) {
            $r = $db->query("SHOW TABLES LIKE '" . $db->escape($p . $t) . "'");
            if ($r->num_rows === 0) {
                $missing[] = $t;
            }
        }
        if ($missing) {
            return ['name' => 'Database tables', 'status' => 'error',
                'detail' => 'Missing: ' . implode(', ', $missing) . '. Reinstall the module.'];
        }
        return ['name' => 'Database tables', 'status' => 'ok', 'detail' => 'All 7 UCP tables present.'];
    }

    private function checkSigningKeys(): array
    {
        $signing = new Signing($this->registry);
        $fp = $signing->fingerprint();
        if ($fp === '') {
            return ['name' => 'Signing keys', 'status' => 'error', 'detail' => 'Keypair missing.'];
        }
        return ['name' => 'Signing keys', 'status' => 'ok', 'detail' => 'Fingerprint: ' . $fp];
    }

    private function checkWellKnown(): array
    {
        $storeUrl = $this->storeUrl();
        if ($storeUrl === '') {
            return ['name' => '.well-known discovery', 'status' => 'warn',
                'detail' => 'Could not determine store URL; verify manually at /.well-known/ucp.'];
        }
        $ch = curl_init($storeUrl . '/.well-known/ucp');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            return ['name' => '.well-known discovery', 'status' => 'error',
                'detail' => 'GET /.well-known/ucp returned HTTP ' . $code .
                    '. Ensure .htaccess rewrites are active or the static dispatcher is in place.'];
        }
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['ucp']['version'])) {
            return ['name' => '.well-known discovery', 'status' => 'error',
                'detail' => 'Response is not valid UCP JSON.'];
        }
        return ['name' => '.well-known discovery', 'status' => 'ok',
            'detail' => 'Serving UCP ' . $json['ucp']['version'] . '.'];
    }

    private function checkPhpVersion(): array
    {
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            return ['name' => 'PHP version', 'status' => 'error',
                'detail' => 'Need PHP 8.1+. Running ' . PHP_VERSION . '.'];
        }
        return ['name' => 'PHP version', 'status' => 'ok', 'detail' => PHP_VERSION];
    }

    private function checkHttps(): array
    {
        $url = $this->storeUrl();
        if (str_starts_with($url, 'https://')) {
            return ['name' => 'HTTPS', 'status' => 'ok', 'detail' => 'Store URL is HTTPS.'];
        }
        return ['name' => 'HTTPS', 'status' => 'warn',
            'detail' => 'Store URL is not HTTPS. UCP agents require HTTPS in production.'];
    }

    private function storeUrl(): string
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $url = (string) ($config->get('config_url') ?? $config->get('config_ssl') ?? '');
        return rtrim($url, '/');
    }
}
