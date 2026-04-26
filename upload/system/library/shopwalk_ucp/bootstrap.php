<?php
/**
 * opencart-ucp bootstrap — registers every UCP library class in the autoloader
 * and defines shared constants consumed across the extension.
 *
 * OpenCart 4 controllers require this file once at the top of each request:
 *
 *   require_once DIR_SYSTEM . 'library/shopwalk_ucp/bootstrap.php';
 *
 * All classes live in the `Shopwalk\Ucp` namespace so they do not clash with
 * OpenCart's own library namespace.
 */

declare(strict_types=1);

if (defined('SHOPWALK_UCP_BOOTSTRAPPED')) {
    return;
}
define('SHOPWALK_UCP_BOOTSTRAPPED', true);

define('SHOPWALK_UCP_VERSION',           '0.2.0');
define('SHOPWALK_UCP_SPEC_VERSION',      '2026-04-08');
define('SHOPWALK_UCP_EXTENSION_CODE',    'shopwalk_ucp');
define('SHOPWALK_UCP_TABLE_PREFIX',      DB_PREFIX . 'ucp_');
define('SHOPWALK_UCP_ROUTE_NAMESPACE',   'extension/shopwalk_ucp');
define('SHOPWALK_UCP_IDEMPOTENCY_TTL',   86400);
define('SHOPWALK_UCP_SESSION_TTL',       1800);
define('SHOPWALK_UCP_SHOPWALK_API_BASE', 'https://api.shopwalk.com/api/v1');

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'Shopwalk\\Ucp\\') !== 0) {
        return;
    }
    $relative = substr($class, strlen('Shopwalk\\Ucp\\'));
    $file = __DIR__ . '/' . strtolower(str_replace('\\', '/', $relative)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
