<?php
/**
 * Shopwalk\Ucp\Identity — UCP identity linking capability.
 *
 * Lets an agent link a UCP OAuth session to an OpenCart customer account.
 * Once linked, subsequent checkouts performed via that bearer token apply
 * to the customer's saved addresses and order history.
 */

declare(strict_types=1);

namespace Shopwalk\Ucp;

final class Identity
{
    private \Registry $registry;
    private \DB $db;

    public function __construct(\Registry $registry)
    {
        $this->registry = $registry;
        $this->db = $registry->get('db');
    }

    public function link(array $body, int $authenticatedCustomerId): array
    {
        if ($authenticatedCustomerId <= 0) {
            return ['status' => 401, 'body' => Response::error('unauthenticated', 'Bearer token required')];
        }
        $subject = (string) ($body['subject'] ?? '');
        if ($subject === '') {
            return ['status' => 422, 'body' => Response::error('missing_subject', 'Agent subject identifier required')];
        }

        // Upsert: OpenCart doesn't have a link table by default; store in custom_field JSON.
        $existingQ = $this->db->query(
            "SELECT `custom_field` FROM `" . DB_PREFIX . "customer` " .
            "WHERE `customer_id` = {$authenticatedCustomerId} LIMIT 1"
        );
        if ($existingQ->num_rows === 0) {
            return ['status' => 404, 'body' => Response::error('customer_not_found', (string) $authenticatedCustomerId)];
        }
        $custom = json_decode((string) ($existingQ->row['custom_field'] ?? ''), true);
        if (!is_array($custom)) {
            $custom = [];
        }
        $custom['ucp_links'] = $custom['ucp_links'] ?? [];
        $custom['ucp_links'][$subject] = [
            'subject'    => $subject,
            'linked_at'  => gmdate('c'),
        ];
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "customer` SET " .
            "`custom_field` = '" . $this->db->escape((string) json_encode($custom, JSON_UNESCAPED_SLASHES)) . "' " .
            "WHERE `customer_id` = {$authenticatedCustomerId}"
        );

        return ['status' => 200, 'body' => Response::ok([
            'link_id' => hash('sha256', $subject . ':' . $authenticatedCustomerId),
            'subject' => $subject,
            'customer_id' => (string) $authenticatedCustomerId,
            'linked_at' => gmdate('c'),
        ])];
    }

    public function unlink(string $linkId, int $authenticatedCustomerId): array
    {
        if ($authenticatedCustomerId <= 0) {
            return ['status' => 401, 'body' => Response::error('unauthenticated', 'Bearer token required')];
        }
        $existingQ = $this->db->query(
            "SELECT `custom_field` FROM `" . DB_PREFIX . "customer` " .
            "WHERE `customer_id` = {$authenticatedCustomerId} LIMIT 1"
        );
        if ($existingQ->num_rows === 0) {
            return ['status' => 404, 'body' => Response::error('customer_not_found', (string) $authenticatedCustomerId)];
        }
        $custom = json_decode((string) ($existingQ->row['custom_field'] ?? ''), true);
        if (!is_array($custom) || empty($custom['ucp_links'])) {
            return ['status' => 404, 'body' => Response::error('link_not_found', $linkId)];
        }
        foreach ($custom['ucp_links'] as $subject => $_) {
            if (hash('sha256', $subject . ':' . $authenticatedCustomerId) === $linkId) {
                unset($custom['ucp_links'][$subject]);
                $this->db->query(
                    "UPDATE `" . DB_PREFIX . "customer` SET " .
                    "`custom_field` = '" . $this->db->escape((string) json_encode($custom, JSON_UNESCAPED_SLASHES)) . "' " .
                    "WHERE `customer_id` = {$authenticatedCustomerId}"
                );
                return ['status' => 200, 'body' => Response::ok(['link_id' => $linkId, 'status' => 'unlinked'])];
            }
        }
        return ['status' => 404, 'body' => Response::error('link_not_found', $linkId)];
    }
}
