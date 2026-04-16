# Contributing to opencart-ucp

Thanks for your interest. This extension is vendor-neutral — it implements [ucp.dev](https://ucp.dev) exactly and is governed by the public UCP spec. Contributions that move it closer to spec compliance, improve interoperability with more agents, or fix bugs are all welcome.

## Ground rules

- **UCP spec is the source of truth.** If the spec and this codebase disagree, the codebase is wrong. Link to the spec section in your PR.
- **Vendor-neutral at Tier 1.** Code under `upload/catalog/controller/extension/shopwalk_ucp/` and `upload/system/library/shopwalk_ucp/` must work for any UCP agent, not just Shopwalk. Shopwalk-specific behavior lives under admin dashboard CTAs (Tier 2, optional).
- **No breaking changes without a version bump.** Stores run this in production — a surprise breaking change can take a store offline for AI traffic.
- **PHP 8.1+.** Match the OpenCart 4 minimum. Use typed properties and return types.

## Development setup

1. Clone the repo.
2. `composer install` (installs dev dependencies for tests and linting).
3. Symlink `upload/*` into an OpenCart 4 install:

   ```bash
   cp -R upload/* /path/to/opencart/
   ```

4. In OpenCart admin: **Extensions → Extensions → Modules → Shopwalk UCP → Install**.
5. Open the module and run **Self-test** to verify your install.

## Testing

- `composer test` runs the PHPUnit suite.
- The admin dashboard's **Self-test** panel should pass cleanly against a fresh install.
- For end-to-end testing against a UCP agent, point a test agent at `https://your-store.test/.well-known/ucp` and exercise a full checkout.

## Filing issues

- **Bugs:** include OpenCart version, PHP version, the request/response that failed, and the UCP spec section you expected behavior from.
- **Spec questions:** file an issue at [ucp.dev](https://ucp.dev) first — this repo follows the spec, it doesn't define it.
- **Security:** see [`SECURITY.md`](SECURITY.md). Do not file public issues for security bugs.

## Commit and PR style

- One concern per PR.
- Commit messages: imperative mood, reference spec section when relevant (e.g., `fix: emit totals in minor units per checkout-rest §3.2`).
- PRs should pass the full test suite and lint clean.
