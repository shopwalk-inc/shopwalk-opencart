# Changelog

All notable changes to opencart-ucp are tracked here.
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
and the UCP spec version it targets is noted per release.

## [0.1.0] — 2026-04-16

Initial public release. Tracks UCP spec `2026-04-08`.

### Added
- `/.well-known/ucp` discovery profile with services, capabilities, and signing keys.
- `/.well-known/oauth-authorization-server` (RFC 8414).
- OAuth 2.0 authorization server with PKCE S256 (authorize, token, revoke, userinfo).
- UCP session-based checkout endpoints (`POST/PUT/GET /checkout-sessions`, `/complete`, `/cancel`).
- UCP direct checkout endpoint (`POST /checkout`) returning `payment_url` for payment at the OpenCart native gateway — no Stripe Connect required.
- UCP orders API (`GET /orders`, `GET /orders/{id}`) with full order entity (line_items, fulfillment.expectations, fulfillment.events, adjustments, totals).
- Outbound order webhooks signed per RFC 9421 (`Content-Digest`, `Signature-Input`, `Signature`, `Webhook-Timestamp`, `Webhook-Id`, `UCP-Agent`).
- Idempotency-Key deduplication (24h window).
- UCP response envelope applied to every response (version, capabilities, status).
- Totals always typed arrays in minor units, addresses in schema.org field names.
- Admin dashboard with self-test, signing key fingerprint, webhook subscriptions, and Shopwalk connection CTA.
- CLI self-test and schema install scripts.
