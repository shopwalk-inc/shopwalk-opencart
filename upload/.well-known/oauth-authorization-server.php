<?php
/**
 * Static dispatcher for GET /.well-known/oauth-authorization-server (RFC 8414).
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

$discovery = new \Shopwalk\Ucp\Discovery($registry);

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('Access-Control-Allow-Origin: *');
echo \Shopwalk\Ucp\Response::jsonEncode($discovery->oauthMeta());
