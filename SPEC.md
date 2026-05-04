# shopwalk-opencart — Compliance Map

> UCP spec version: `2026-04-08`
> This document maps each UCP spec requirement to its implementation in this repo.

## 1. Discovery

| Spec                                   | File                                                                                  |
|----------------------------------------|---------------------------------------------------------------------------------------|
| `/.well-known/ucp` profile             | `upload/.well-known/ucp.php` → `system/library/shopwalk_opencart/discovery.php`            |
| `/.well-known/oauth-authorization-server` (RFC 8414) | `upload/.well-known/oauth-authorization-server.php`                       |
| `ucp.version = "2026-04-08"`           | `Discovery::profile()`                                                                |
| `services[]` with version/spec/transport/schema/endpoint | `Discovery::services()`                                             |
| `capabilities[]`                       | `Discovery::capabilities()`                                                           |
| `signing_keys[]`                       | `Discovery::signing_keys()` (generated on activation, stored in `setting` table)      |

## 2. Response envelope

Every response includes:

```json
{ "ucp": {"version": "2026-04-08", "capabilities": [...], "status": "ok|error"}, ... }
```

Implemented in `system/library/shopwalk_opencart/response.php` (`Response::envelope()`).

## 3. Totals

All totals are typed arrays of integer minor units (no floats):

```json
[{"type": "subtotal", "amount": 9999}, {"type": "total", "amount": 10946}]
```

Implemented in `Response::totals()`.

## 4. Addresses

Schema.org naming — `street_address`, `address_locality`, `address_region`, `postal_code`, `address_country`.
Conversion in `Response::address()` and `Checkout::destinations()`.

## 5. Fulfillment

`fulfillment.methods[]` with nested `destinations[]` and `groups[]` of `options[]`. See `Checkout::fulfillment()`.

## 6. Checkout (session-based)

| Request                                  | Implementation                                            |
|------------------------------------------|-----------------------------------------------------------|
| `POST /checkout-sessions`                | `Checkout::create()`                                      |
| `PUT /checkout-sessions/{id}`            | `Checkout::update()` — buyer, fulfillment, payment       |
| `GET /checkout-sessions/{id}`            | `Checkout::fetch()`                                       |
| `POST /checkout-sessions/{id}/complete`  | `Checkout::complete()`                                    |
| `POST /checkout-sessions/{id}/cancel`    | `Checkout::cancel()`                                      |

Lifecycle: `incomplete → ready_for_complete → completed | canceled | requires_escalation`.

## 7. Direct checkout (UCP extension)

`POST /ucp/v1/checkout` — single call that creates an OpenCart order (status: pending) and returns a `payment_url`. Customer pays via OpenCart's native gateway. Implemented in `system/library/shopwalk_opencart/direct_checkout.php`. See `UCP_DIRECT_CHECKOUT.md` in the shopwalk-infra spec.

## 8. Orders

| Request                          | Implementation                                                        |
|----------------------------------|-----------------------------------------------------------------------|
| `GET /orders`                    | `Orders::list()`                                                      |
| `GET /orders/{id}`               | `Orders::fetch()` — returns full UCP order entity                     |

`line_items[].quantity` is an object: `{original, total, fulfilled}`. `fulfillment.expectations[]` describes what will ship; `fulfillment.events[]` is an append-only log of state changes. `adjustments[]` holds refunds.

## 9. Webhooks (outbound)

Triggered on OpenCart order status change. Signed per RFC 9421:

```
POST <callback_url>
Webhook-Timestamp: 1713264000
Webhook-Id: evt_abc123
UCP-Agent: profile="https://store.com/.well-known/ucp"
Content-Digest: sha-256=:base64:
Signature-Input: sig1=("content-digest" "webhook-id" "webhook-timestamp");keyid="store-key";alg="hmac-sha256"
Signature: sig1=:base64:
```

Implemented in `system/library/shopwalk_opencart/webhook_delivery.php`.

## 10. Idempotency

`Idempotency-Key` stored for 24h. Duplicate key with same body returns cached result; with different body returns HTTP 409.
Implemented in `system/library/shopwalk_opencart/idempotency.php`.

## 11. Request headers accepted

- `Authorization: Bearer <token>`
- `UCP-Agent: profile="https://agent.example.com/.well-known/ucp"`
- `Request-Signature: <detached JWT>` (optional; verified when `signing_keys` in agent profile)
- `Idempotency-Key: <uuid>`
- `Request-Id: <uuid>`

## 12. OAuth 2.0

Full OAuth 2.0 authorization server with PKCE S256. Scopes:

- `ucp:scopes:checkout_session`
- `ucp:scopes:orders`
- `ucp:scopes:webhooks`
- `ucp:scopes:catalog`

Implemented in `system/library/shopwalk_opencart/oauth_server.php` and `oauth_clients.php`.

## 13. Catalog

`GET /catalog/products` and `GET /catalog/products/{id}` expose the OpenCart catalog in UCP catalog format. Implemented in `system/library/shopwalk_opencart/catalog.php`.

## 14. Identity linking

`POST /identity/link` and `DELETE /identity/link/{id}` allow an agent-authenticated user to link an OpenCart customer account. Implemented in `system/library/shopwalk_opencart/identity.php`.

## Coverage summary

| Spec section              | Status   |
|---------------------------|----------|
| Discovery                 | ✅ full  |
| Checkout REST             | ✅ full  |
| Direct checkout (ext.)    | ✅ full  |
| Order                     | ✅ full  |
| Catalog                   | ✅ full  |
| Identity linking          | ✅ full  |
| Webhooks (RFC 9421)       | ✅ full  |
| OAuth 2.0 (PKCE)          | ✅ full  |
| Idempotency               | ✅ full  |
| Response envelope         | ✅ full  |
