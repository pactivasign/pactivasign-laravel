# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.1] - 2026-10-30

### Changed

- `pactivasign:test` first checks that the cache store can take the lock used for token refresh. If it cannot,
  for example the database store without a `cache_locks` table, it prints the fix instead of a raw SQL error:
  `php artisan cache:table` when neither cache table exists, a migration for `cache_locks` alone when the `cache`
  table already does, or `PACTIVASIGN_CACHE_STORE=file`.

## [0.2.0] - 2026-10-30

### Added

- Laravel 10 (10.48+) and Laravel 11 support. PHP 8.2+ is still required, so Laravel 10 apps on PHP 8.1 cannot
  install it. On Laravel 10, `vendor:publish --tag=pactivasign-migrations` copies the migration with its own
  timestamp, because `publishesMigrations()` only exists from Laravel 11.

## [0.1.1] - 2026-10-26

### Fixed

- Installs on a new Laravel 13 app: allow Guzzle 8 and guzzlehttp/psr7 3, which Laravel 13 ships with.
- Webhooks on Laravel 13 were rejected with 419: the route now also skips `PreventRequestForgery`, Laravel 13's
  CSRF middleware.
- `pactivasign:webhook-test` explains a missing `PACTIVASIGN_WEBHOOK_SECRET` instead of throwing.

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

[Unreleased]: https://github.com/pactivasign/pactivasign-laravel/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/pactivasign/pactivasign-laravel/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/pactivasign/pactivasign-laravel/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/pactivasign/pactivasign-laravel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/pactivasign/pactivasign-laravel/releases/tag/v0.1.0
