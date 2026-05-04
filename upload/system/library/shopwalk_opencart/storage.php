<?php
/**
 * Shopwalk\Opencart\Storage — table creation, migration, teardown.
 *
 * Called from the admin install/uninstall controller. All UCP tables are
 * prefixed with `{DB_PREFIX}ucp_` so they never collide with OpenCart core
 * or other extensions.
 */

declare(strict_types=1);

namespace Shopwalk\Opencart;

final class Storage
{
    private \DB $db;

    public function __construct(\Registry $registry)
    {
        $this->db = $registry->get('db');
    }

    public function install(): void
    {
        $p = SHOPWALK_UCP_TABLE_PREFIX;

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}checkout_sessions` (
            `id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `agent_client_id` VARCHAR(64) DEFAULT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'incomplete',
            `opencart_order_id` INT UNSIGNED DEFAULT NULL,
            `buyer` MEDIUMTEXT NULL,
            `line_items` MEDIUMTEXT NOT NULL,
            `fulfillment` MEDIUMTEXT NULL,
            `payment` MEDIUMTEXT NULL,
            `totals` MEDIUMTEXT NULL,
            `currency` VARCHAR(8) NOT NULL DEFAULT 'USD',
            `messages` MEDIUMTEXT NULL,
            `metadata` MEDIUMTEXT NULL,
            `created_at` INT UNSIGNED NOT NULL,
            `updated_at` INT UNSIGNED NOT NULL,
            `expires_at` INT UNSIGNED NOT NULL,
            INDEX `idx_status` (`status`),
            INDEX `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}oauth_clients` (
            `client_id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `client_secret_hash` VARCHAR(128) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `profile_url` VARCHAR(512) NULL,
            `redirect_uris` TEXT NOT NULL,
            `scopes` VARCHAR(512) NOT NULL DEFAULT 'ucp:scopes:checkout_session ucp:scopes:orders',
            `created_at` INT UNSIGNED NOT NULL,
            `revoked_at` INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}oauth_tokens` (
            `token` VARCHAR(128) NOT NULL PRIMARY KEY,
            `type` VARCHAR(16) NOT NULL,
            `client_id` VARCHAR(64) NOT NULL,
            `customer_id` INT UNSIGNED NULL,
            `scopes` VARCHAR(512) NOT NULL,
            `refresh_token` VARCHAR(128) NULL,
            `code_challenge` VARCHAR(128) NULL,
            `redirect_uri` VARCHAR(512) NULL,
            `created_at` INT UNSIGNED NOT NULL,
            `expires_at` INT UNSIGNED NOT NULL,
            `revoked_at` INT UNSIGNED NULL,
            INDEX `idx_client_customer` (`client_id`, `customer_id`),
            INDEX `idx_refresh` (`refresh_token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}webhook_subscriptions` (
            `id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `client_id` VARCHAR(64) NOT NULL,
            `callback_url` VARCHAR(512) NOT NULL,
            `events` VARCHAR(512) NOT NULL DEFAULT 'order.*',
            `secret` VARCHAR(128) NULL,
            `created_at` INT UNSIGNED NOT NULL,
            `disabled_at` INT UNSIGNED NULL,
            INDEX `idx_client` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}webhook_deliveries` (
            `id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `subscription_id` VARCHAR(64) NOT NULL,
            `event_type` VARCHAR(64) NOT NULL,
            `payload` MEDIUMTEXT NOT NULL,
            `http_status` INT NULL,
            `response_body` TEXT NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `next_attempt_at` INT UNSIGNED NULL,
            `delivered_at` INT UNSIGNED NULL,
            `created_at` INT UNSIGNED NOT NULL,
            INDEX `idx_sub` (`subscription_id`),
            INDEX `idx_pending` (`delivered_at`, `next_attempt_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}idempotency` (
            `idempotency_key` VARCHAR(128) NOT NULL,
            `client_id` VARCHAR(64) NOT NULL DEFAULT '',
            `request_hash` VARCHAR(64) NOT NULL,
            `response_body` MEDIUMTEXT NOT NULL,
            `response_status` INT NOT NULL DEFAULT 200,
            `created_at` INT UNSIGNED NOT NULL,
            `expires_at` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`idempotency_key`, `client_id`),
            INDEX `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$p}order_events` (
            `id` VARCHAR(64) NOT NULL PRIMARY KEY,
            `order_id` INT UNSIGNED NOT NULL,
            `type` VARCHAR(32) NOT NULL,
            `description` TEXT NULL,
            `payload` MEDIUMTEXT NULL,
            `occurred_at` INT UNSIGNED NOT NULL,
            INDEX `idx_order` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function uninstall(bool $dropData = false): void
    {
        if (!$dropData) {
            return;
        }
        $p = SHOPWALK_UCP_TABLE_PREFIX;
        foreach (
            [
                'checkout_sessions', 'oauth_clients', 'oauth_tokens',
                'webhook_subscriptions', 'webhook_deliveries',
                'idempotency', 'order_events',
            ] as $t
        ) {
            $this->db->query("DROP TABLE IF EXISTS `{$p}{$t}`");
        }
    }

    public function cleanupExpired(): int
    {
        $p = SHOPWALK_UCP_TABLE_PREFIX;
        $now = time();
        $this->db->query("DELETE FROM `{$p}idempotency` WHERE `expires_at` < {$now}");
        $a = $this->db->countAffected();
        $this->db->query(
            "UPDATE `{$p}checkout_sessions` SET `status` = 'canceled', `updated_at` = {$now} " .
            "WHERE `status` IN ('incomplete','ready_for_complete') AND `expires_at` < {$now}"
        );
        $a += $this->db->countAffected();
        $this->db->query("DELETE FROM `{$p}oauth_tokens` WHERE `expires_at` < {$now} AND `type` = 'access'");
        $a += $this->db->countAffected();
        return $a;
    }
}
