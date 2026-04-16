<?php
/**
 * Static dispatcher for GET /.well-known/ucp.
 *
 * Apache and Nginx both let `.well-known/*` fall through to the filesystem
 * ahead of OpenCart's router. Placing this file at the store's web root
 * makes the UCP discovery document addressable without needing OpenCart
 * rewrite rules. It boots OpenCart only far enough to construct the
 * Discovery library, then emits JSON.
 */

declare(strict_types=1);

$root = __DIR__ . '/..';
foreach (['config.php', 'admin/config.php'] as $candidate) {
    if (is_file($root . '/' . $candidate)) {
        require_once $root . '/' . $candidate;
        break;
    }
}
if (!defined('DIR_APPLICATION') || !defined('DIR_SYSTEM')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'OpenCart config not found'], JSON_UNESCAPED_SLASHES);
    exit;
}

require_once DIR_SYSTEM . 'startup.php';
require_once DIR_SYSTEM . 'library/shopwalk_ucp/bootstrap.php';

$registry = new \Opencart\System\Engine\Registry();
$config = new \Opencart\System\Engine\Config();
$config->addPath(DIR_CONFIG);
$config->load('default');
$config->load('catalog');
$registry->set('config', $config);

$db = new \Opencart\System\Library\DB($config->get('db_engine'),
    $config->get('db_hostname'), $config->get('db_username'),
    $config->get('db_password'), $config->get('db_database'),
    $config->get('db_port'));
$registry->set('db', $db);

$discovery = new \Shopwalk\Ucp\Discovery($registry);

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('Access-Control-Allow-Origin: *');
echo \Shopwalk\Ucp\Response::jsonEncode($discovery->profile());
