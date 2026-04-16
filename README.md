# opencart-ucp — Universal Commerce Protocol adapter for OpenCart

**Make any OpenCart store fully purchasable by UCP-compliant AI shopping agents.**

This extension implements the [Universal Commerce Protocol](https://ucp.dev) — an open standard backed by Google, Shopify, Walmart, Stripe, PayPal, Visa, Mastercard, and ~30 other industry leaders — on the OpenCart side (UCP Business). Any UCP agent (Shopwalk, OpenAI, Anthropic, LangChain, custom) can discover your store, create carts, and place orders programmatically.

> **Status:** Alpha. Tracks UCP spec version `2026-04-08`.
> **Platform:** OpenCart 4.x.
> **License:** GPL-2.0-or-later.

## What it does

- Serves `/.well-known/ucp` and `/.well-known/oauth-authorization-server` discovery documents.
- Implements the UCP **checkout-sessions** REST API (`incomplete → ready_for_complete → completed`).
- Implements the UCP **direct checkout** extension — one call returns a `payment_url` that customers complete on OpenCart's native checkout (no Stripe Connect, no commission).
- Implements the UCP **orders** API — full order entity with `line_items`, `fulfillment.expectations`, `fulfillment.events`, `adjustments`, and typed minor-unit `totals`.
- Ships an OAuth 2.0 authorization server (PKCE S256) for agent identity linking.
- Delivers order lifecycle webhooks signed per RFC 9421 (HTTP Message Signatures) with `Content-Digest`, `Signature-Input`, and `Signature` headers.
- Verifies inbound agent requests via `Idempotency-Key`, `UCP-Agent`, and `Request-Signature`.
- Admin dashboard with self-test, signing-key fingerprint, webhook subscriptions, and optional Shopwalk connection.

## Two-tier architecture

The extension is **vendor-neutral** — it implements ucp.dev exactly. You do not need a Shopwalk account to use it.

- **Tier 1 (default, no signup):** UCP-compliant endpoints live. Any agent that speaks UCP can transact with your store.
- **Tier 2 (optional, free):** Click "Connect to Shopwalk" in the admin dashboard to unlock real-time product sync, partner portal, brand customization, and analytics.

## Install

1. Download the latest release zip from the Releases page.
2. In OpenCart admin: **Extensions → Installer** → upload the zip.
3. **Extensions → Extensions → Modules** → install **Shopwalk UCP**.
4. Open the module and click **Run self-test**. All checks should pass.
5. (Optional) Paste a Shopwalk license key to enable Tier 2 features.

Or clone this repo and symlink `upload/*` into your OpenCart install:

```bash
git clone https://github.com/shopwalk-inc/opencart-ucp.git
cp -R opencart-ucp/upload/* /path/to/opencart/
```

## Endpoints exposed

| Method  | Path                                                    | Purpose                    |
|---------|---------------------------------------------------------|----------------------------|
| GET     | `/.well-known/ucp`                                      | UCP discovery profile      |
| GET     | `/.well-known/oauth-authorization-server`               | RFC 8414 metadata          |
| POST    | `/index.php?route=extension/shopwalk_ucp/checkout`      | Create checkout session    |
| PUT/GET | `/index.php?route=extension/shopwalk_ucp/checkout/{id}` | Update / fetch session     |
| POST    | `/index.php?route=extension/shopwalk_ucp/checkout/{id}/complete` | Place order       |
| POST    | `/index.php?route=extension/shopwalk_ucp/checkout/{id}/cancel`   | Cancel session    |
| POST    | `/index.php?route=extension/shopwalk_ucp/direct`        | Direct checkout (returns `payment_url`) |
| GET     | `/index.php?route=extension/shopwalk_ucp/orders`        | List orders                |
| GET     | `/index.php?route=extension/shopwalk_ucp/orders/{id}`   | Order detail               |
| *       | `/index.php?route=extension/shopwalk_ucp/oauth/*`       | OAuth authorize/token/revoke/userinfo |

With clean-URL rewrites enabled (`.htaccess` shipped in this repo), these are reachable at `/ucp/v1/*` and `/.well-known/*` at the store root.

## Spec references

- [ucp.dev](https://ucp.dev) — protocol homepage
- [Checkout REST](https://ucp.dev/latest/specification/checkout-rest/)
- [Order](https://ucp.dev/latest/specification/order/)
- [Catalog](https://ucp.dev/latest/specification/catalog/)
- [Identity linking](https://ucp.dev/latest/specification/identity-linking/)

See [`SPEC.md`](SPEC.md) for a detailed map of this extension's coverage.

## Related projects

- [`woocommerce-ucp`](https://github.com/shopwalk-inc/woocommerce-ucp) — same adapter for WooCommerce.
- [`magento-ucp`](https://github.com/shopwalk-inc/magento-ucp) — same adapter for Magento 2.
- [`prestashop-ucp`](https://github.com/shopwalk-inc/prestashop-ucp) — same adapter for PrestaShop.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). Security issues: [`SECURITY.md`](SECURITY.md).
