<?php
/**
 * Standalone installer — creates the UCP database tables and generates a
 * signing keypair. Run from the OpenCart root:
 *
 *   php opencart-ucp/install/install.php
 *
 * Intended for sysadmins who can't (or don't want to) use the OpenCart
 * Extensions → Installer UI. The admin controller runs the same logic via
 * the OpenCart extension installer.
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
    fwrite(STDERR, "Run this script from the OpenCart root (where config.php lives).\n");
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

(new \Shopwalk\Ucp\Storage($registry))->install();
$signing = new \Shopwalk\Ucp\Signing($registry);
$pair = $signing->keypair();

echo "✓ Tables created\n";
echo "✓ Signing keypair: kid={$pair['kid']}, fingerprint=" . $signing->fingerprint() . "\n";
echo "\nNext: visit admin → Extensions → Modules → Shopwalk UCP to configure.\n";
