<?php
/**
 * Shopwalk\Opencart\Catalog — UCP catalog capability.
 *
 * Exposes the OpenCart catalog in UCP catalog format so agents can discover
 * products before creating a checkout. This is a thin projection of
 * `oc_product` + `oc_product_description` with schema.org-style fields
 * and minor-unit prices.
 */

declare(strict_types=1);

namespace Shopwalk\Opencart;

final class Catalog
{
    private \Registry $registry;
    private \DB $db;

    public function __construct(\Registry $registry)
    {
        $this->registry = $registry;
        $this->db = $registry->get('db');
    }

    public function listProducts(array $query): array
    {
        $limit = max(1, min(200, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $langId = $this->languageId();
        $currency = $this->currency();

        $search = '';
        if (!empty($query['search'])) {
            $search = " AND pd.`name` LIKE '%" . $this->db->escape((string) $query['search']) . "%'";
        }

        $rows = $this->db->query(
            "SELECT p.`product_id`, p.`price`, p.`image`, p.`quantity`, p.`subtract`, p.`status`, pd.`name`, pd.`description` " .
            "FROM `" . DB_PREFIX . "product` p " .
            "LEFT JOIN `" . DB_PREFIX . "product_description` pd " .
            "  ON pd.`product_id` = p.`product_id` AND pd.`language_id` = {$langId} " .
            "WHERE p.`status` = 1 {$search} " .
            "ORDER BY p.`product_id` DESC LIMIT {$limit} OFFSET {$offset}"
        )->rows;

        $products = [];
        foreach ($rows as $row) {
            $products[] = $this->shape($row, $currency);
        }

        return ['status' => 200, 'body' => Response::ok([
            'products' => $products,
            'pagination' => [
                'limit'  => $limit,
                'offset' => $offset,
                'next'   => count($products) === $limit ? $offset + $limit : null,
            ],
        ])];
    }

    public function fetchProduct(int $productId): array
    {
        if ($productId <= 0) {
            return ['status' => 400, 'body' => Response::error('invalid_id', 'Product id is required')];
        }
        $langId = $this->languageId();
        $r = $this->db->query(
            "SELECT p.`product_id`, p.`price`, p.`image`, p.`quantity`, p.`subtract`, p.`status`, pd.`name`, pd.`description` " .
            "FROM `" . DB_PREFIX . "product` p " .
            "LEFT JOIN `" . DB_PREFIX . "product_description` pd " .
            "  ON pd.`product_id` = p.`product_id` AND pd.`language_id` = {$langId} " .
            "WHERE p.`product_id` = {$productId} AND p.`status` = 1 LIMIT 1"
        );
        if ($r->num_rows === 0) {
            return ['status' => 404, 'body' => Response::error('product_not_found', (string) $productId)];
        }
        return ['status' => 200, 'body' => Response::ok($this->shape($r->row, $this->currency()))];
    }

    private function shape(array $row, string $currency): array
    {
        $inStock = !(int) $row['subtract'] || (int) $row['quantity'] > 0;
        return [
            'id'            => (string) $row['product_id'],
            'title'         => (string) $row['name'],
            'description'   => strip_tags((string) ($row['description'] ?? '')),
            'price'         => Response::toMinor((float) $row['price'], $currency),
            'currency'      => $currency,
            'availability'  => $inStock ? 'in_stock' : 'out_of_stock',
            'image_url'     => $this->imageUrl((string) $row['image']),
            'permalink_url' => $this->productLink((int) $row['product_id']),
        ];
    }

    private function imageUrl(string $image): string
    {
        if ($image === '') {
            return '';
        }
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $base = (string) ($config->get('config_url') ?? $config->get('config_ssl') ?? '');
        return rtrim($base, '/') . '/image/' . ltrim($image, '/');
    }

    private function productLink(int $productId): string
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $base = (string) ($config->get('config_url') ?? $config->get('config_ssl') ?? '');
        return rtrim($base, '/') . '/index.php?route=product/product&product_id=' . $productId;
    }

    private function currency(): string
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        return strtoupper((string) ($config->get('config_currency') ?? 'USD'));
    }

    private function languageId(): int
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        return (int) ($config->get('config_language_id') ?? 1);
    }
}
