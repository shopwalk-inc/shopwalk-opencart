<?php
/**
 * Cron-friendly worker — cleans up expired idempotency keys & sessions,
 * flushes pending webhook deliveries.
 *
 * Sample crontab (every minute):
 *   * * * * * php /path/to/opencart/opencart-ucp/install/cron.php
 */

declare(strict_types=1);

$root = getcwd();
foreach (['config.php', 'admin/config.php'] as $cfg) {
    if (is_file($root . '/' . $cfg)) {
        require_once $root . '/' . $cfg;
        break;
    }
}
if (!defined('DIR_SYSTEM')) {
    fwrite(STDERR, "Run from OpenCart root.\n");
    exit(1);
}

require_once DIR_SYSTEM . 'startup.php';
require_once DIR_SYSTEM . 'library/shopwalk_ucp/bootstrap.php';

$registry = new \Opencart\System\Engine\Registry();
$config = new \Opencart\System\Engine\Config();
$config->addPath(DIR_CONFIG);
$config->load('default');
$config->load('catalog');
$registry->set('config', $config);

$db = new \Opencart\System\Library\DB(
    $config->get('db_engine'),
    $config->get('db_hostname'), $config->get('db_username'),
    $config->get('db_password'), $config->get('db_database'),
    $config->get('db_port')
);
$registry->set('db', $db);

$storage = new \Shopwalk\Ucp\Storage($registry);
$cleaned = $storage->cleanupExpired();

$delivery = new \Shopwalk\Ucp\WebhookDelivery($registry);
$delivered = $delivery->flushPending(50);

echo "[" . gmdate('c') . "] cleaned={$cleaned} delivered={$delivered}\n";
