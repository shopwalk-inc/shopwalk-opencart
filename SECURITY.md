# Security Policy

## Reporting a vulnerability

Please do **not** open a public GitHub issue for security vulnerabilities. Email `security@shopwalk.com` with:

- A description of the issue and its impact.
- Reproduction steps (request/response samples, shell commands).
- The OpenCart version and shopwalk-opencart version you tested against.
- Your disclosure preferences.

We aim to acknowledge within one business day and provide a remediation plan within seven. Credit is given in the release notes unless you prefer otherwise.

## Supported versions

The latest minor release receives security fixes. Older releases may get fixes for high-severity issues on a case-by-case basis.

| Version | Supported |
|---------|-----------|
| 0.x     | ✅        |

## Threat model

This extension exposes HTTP endpoints that accept requests from AI agents on the open internet.
Specific guarantees:

- All agent requests must carry `Authorization: Bearer <token>` obtained through the bundled OAuth 2.0 authorization server (PKCE S256).
- `Idempotency-Key` is enforced on `POST`/`PUT` checkout mutations; duplicate requests with different bodies return HTTP 409.
- Outbound webhooks are signed per RFC 9421 with `Content-Digest`, `Signature-Input`, and `Signature` headers.
- The signing keypair is generated on activation and stored in the OpenCart `setting` table. Rotate with `php install/rotate-keys.php` — rotation publishes the new public key in `/.well-known/ucp` alongside the old one for a 24-hour overlap window.

Out of scope:

- Vulnerabilities in OpenCart core (report upstream at [opencart/opencart](https://github.com/opencart/opencart)).
- Vulnerabilities in third-party OpenCart extensions other than this one.
- Misconfiguration of the hosting environment (TLS termination, firewall, etc.).
