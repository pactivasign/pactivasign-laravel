# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.1] - 2026-10-26

### Fixed

- Installs on a new Laravel 13 app: allow Guzzle 8 and guzzlehttp/psr7 3, which Laravel 13 ships with.

## [0.1.0] - 2026-10-26

First public release, for Laravel 12 and 13 with `pactivasign/pactivasign-php` 0.1. Pre-1.0: minor versions may
contain breaking changes.

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

[Unreleased]: https://github.com/pactivasign/pactivasign-laravel/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/pactivasign/pactivasign-laravel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/pactivasign/pactivasign-laravel/releases/tag/v0.1.0
