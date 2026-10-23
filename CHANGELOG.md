# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Auto-discovered service provider and `PactivaSign` facade; `PactivaSign\Client` resolvable from the container.
- `config/pactivasign.php`: keys from a file, an (encrypted) `.env` value or a custom `KeyProvider`; the base URL
  defaults to the public sandbox.
- Tokens cached in a Laravel cache store, with atomic-lock refresh across workers.
- `Route::pactivaSignWebhooks()`: verified webhook endpoint without CSRF, storing each event once.
- Laravel events per webhook type (`EnvelopeCompleted`, `RecipientSigned`, …) plus `WebhookReceived`, with
  `isStale()` for out-of-order deliveries; dispatched from a queued job.
- Stored deliveries pruned after 30 days by `model:prune`.
- Commands: `pactivasign:keys`, `pactivasign:test`, `pactivasign:webhook-test`.
- `PactivaSign::fake()` for tests, with request assertions and signed webhook payloads.
