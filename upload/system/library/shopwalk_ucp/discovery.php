<?php
/**
 * Shopwalk\Ucp\Discovery — builds the /.well-known/ucp profile and the
 * /.well-known/oauth-authorization-server RFC 8414 document.
 *
 * The catalog controller handlers call profile() / oauthMeta() and write the
 * result directly to the HTTP response.
 */

declare(strict_types=1);

namespace Shopwalk\Ucp;

final class Discovery
{
    private \Registry $registry;
    private Signing $signing;

    public function __construct(\Registry $registry)
    {
        $this->registry = $registry;
        $this->signing = new Signing($registry);
    }

    public function profile(): array
    {
        $storeUrl = $this->storeUrl();
        $base = $storeUrl . '/index.php?route=' . SHOPWALK_UCP_ROUTE_NAMESPACE;

        $service = static fn(string $slug): array => [
            'version'   => SHOPWALK_UCP_SPEC_VERSION,
            'spec'      => 'https://ucp.dev/latest/specification/' . $slug . '/',
            'transport' => 'rest',
            'schema'    => 'https://ucp.dev/schemas/' . $slug . '/' . SHOPWALK_UCP_SPEC_VERSION . '.json',
            'endpoint'  => $base,
        ];

        $capability = static fn(string $slug): array => [
            'version' => SHOPWALK_UCP_SPEC_VERSION,
            'spec'    => 'https://ucp.dev/latest/specification/' . $slug . '/',
        ];

        return [
            'ucp' => [
                'version' => SHOPWALK_UCP_SPEC_VERSION,
                'services' => [
                    'dev.ucp.shopping.checkout'       => $service('checkout-rest'),
                    'dev.ucp.shopping.order'          => $service('order'),
                    'dev.ucp.shopping.catalog'        => $service('catalog'),
                    'dev.ucp.common.identity_linking' => $service('identity-linking'),
                ],
                'capabilities' => [
                    'dev.ucp.shopping.checkout'       => $capability('checkout-rest'),
                    'dev.ucp.shopping.order'          => $capability('order'),
                    'dev.ucp.shopping.catalog'        => $capability('catalog'),
                    'dev.ucp.common.identity_linking' => $capability('identity-linking'),
                ],
                'payment_handlers' => new \stdClass(),
                'signing_keys'     => [$this->signing->publicJwk()],
            ],
            'id'    => $storeUrl,
            'name'  => $this->storeName(),
            'oauth' => [
                'authorization_server' => $storeUrl . '/.well-known/oauth-authorization-server',
            ],
            'platform' => 'opencart',
            'plugin'   => [
                'name'    => 'Shopwalk UCP — Universal Commerce Protocol adapter',
                'version' => SHOPWALK_UCP_VERSION,
                'source'  => 'https://github.com/shopwalk-inc/opencart-ucp',
            ],
        ];
    }

    public function oauthMeta(): array
    {
        $storeUrl = $this->storeUrl();
        $base = $storeUrl . '/index.php?route=' . SHOPWALK_UCP_ROUTE_NAMESPACE . '/oauth';
        return [
            'issuer'                              => $storeUrl,
            'authorization_endpoint'              => $base . '/authorize',
            'token_endpoint'                      => $base . '/token',
            'revocation_endpoint'                 => $base . '/revoke',
            'userinfo_endpoint'                   => $base . '/userinfo',
            'registration_endpoint'               => $base . '/register',
            'scopes_supported'                    => [
                'ucp:scopes:checkout_session',
                'ucp:scopes:orders',
                'ucp:scopes:webhooks',
                'ucp:scopes:catalog',
                'ucp:scopes:identity',
            ],
            'response_types_supported'            => ['code'],
            'grant_types_supported'               => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported'    => ['S256'],
        ];
    }

    private function storeUrl(): string
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        $url = (string) ($config->get('config_ssl') ?? $config->get('config_url') ?? '');
        if ($url === '' && isset($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/';
        }
        return rtrim($url, '/');
    }

    private function storeName(): string
    {
        /** @var \Cart\Config $config */
        $config = $this->registry->get('config');
        return (string) ($config->get('config_name') ?? 'OpenCart store');
    }
}
