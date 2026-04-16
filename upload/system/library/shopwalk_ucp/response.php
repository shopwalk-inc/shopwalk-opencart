<?php
/**
 * Shopwalk\Ucp\Response — builds UCP-compliant response bodies.
 *
 * Every UCP response carries:
 *   - a `ucp` envelope (version, capabilities, status)
 *   - typed minor-unit totals arrays (never floats)
 *   - schema.org address field names (never WC-style line1/city/state)
 *   - structured messages[] for errors
 *
 * Controllers call Response::ok($data) / Response::error($code, $msg) to get
 * the final array that is JSON-encoded and written to the HTTP response.
 */

declare(strict_types=1);

namespace Shopwalk\Ucp;

final class Response
{
    public const CAPABILITIES = [
        'dev.ucp.shopping.checkout',
        'dev.ucp.shopping.order',
        'dev.ucp.shopping.catalog',
        'dev.ucp.common.identity_linking',
    ];

    public static function ok(array $body, array $capabilities = self::CAPABILITIES): array
    {
        return self::envelope('ok', $capabilities) + $body;
    }

    public static function error(
        string $code,
        string $content,
        string $severity = 'unrecoverable',
        array $extra = []
    ): array {
        return self::envelope('error') + [
            'messages' => [[
                'type'     => 'error',
                'code'     => $code,
                'content'  => $content,
                'severity' => $severity,
            ]],
        ] + $extra;
    }

    public static function envelope(string $status, array $capabilities = self::CAPABILITIES): array
    {
        return [
            'ucp' => [
                'version'      => SHOPWALK_UCP_SPEC_VERSION,
                'capabilities' => $capabilities,
                'status'       => $status,
            ],
        ];
    }

    /**
     * Build a typed totals array in minor units.
     *
     * @param array<string,int> $parts e.g. ['subtotal' => 9999, 'shipping' => 599, 'tax' => 848]
     *                                  Missing keys are omitted. 'total' is computed if not supplied.
     */
    public static function totals(array $parts): array
    {
        $order = ['subtotal', 'shipping', 'tax', 'discount', 'fee'];
        $totals = [];
        $sum = 0;
        foreach ($order as $type) {
            if (array_key_exists($type, $parts)) {
                $amount = (int) $parts[$type];
                $totals[] = ['type' => $type, 'amount' => $amount];
                $sum += $amount;
            }
        }
        $total = array_key_exists('total', $parts) ? (int) $parts['total'] : $sum;
        $totals[] = ['type' => 'total', 'amount' => $total];
        return $totals;
    }

    /**
     * Convert OpenCart minor currency unit. OpenCart stores totals as floats
     * in the store base currency (e.g. 12.99). UCP wants integer minor units.
     */
    public static function toMinor(float|int|string $amount, string $currency = 'USD'): int
    {
        $zeroDecimal = ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'];
        $factor = in_array(strtoupper($currency), $zeroDecimal, true) ? 1 : 100;
        return (int) round(((float) $amount) * $factor);
    }

    /**
     * Convert a flat address (keys: line1, line2, city, state, postcode, country)
     * to the UCP Destination shape (schema.org field names).
     *
     * @param array<string,mixed> $address
     */
    public static function address(array $address, ?string $id = null): array
    {
        $street = trim(($address['line1'] ?? $address['address_1'] ?? '') .
            (empty($address['line2']) && empty($address['address_2']) ? '' :
                "\n" . ($address['line2'] ?? $address['address_2'] ?? '')));
        $dest = [
            'street_address'   => $street,
            'address_locality' => (string) ($address['city'] ?? ''),
            'address_region'   => (string) ($address['state'] ?? $address['region'] ?? ''),
            'postal_code'      => (string) ($address['postcode'] ?? $address['postal_code'] ?? ''),
            'address_country'  => strtoupper((string) ($address['country'] ?? $address['country_code'] ?? 'US')),
        ];
        if ($id !== null) {
            $dest = ['id' => $id] + $dest;
        }
        return $dest;
    }

    /**
     * Build a `messages[]` entry for an info-level notice (non-error).
     */
    public static function message(string $code, string $content, string $severity = 'recoverable'): array
    {
        return ['type' => 'info', 'code' => $code, 'content' => $content, 'severity' => $severity];
    }

    public static function jsonEncode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
