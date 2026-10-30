# PactivaSign for Laravel

Laravel integration for the [PactivaSign PHP SDK](https://github.com/pactivasign/pactivasign-php): send
documents for signature from a facade, and receive verified webhooks as Laravel events.

> **Status: pre-release.** Until 1.0, minor versions may change the API.

Requires PHP 8.2+ and Laravel 10.48+, 11, 12 or 13.

## Installation

```bash
composer require pactivasign/pactivasign-laravel
php artisan vendor:publish --tag=pactivasign-config        # optional: config/pactivasign.php
```

## Keys and credentials

PactivaSign uses OAuth with two private keys instead of a shared secret. Generate them once:

```bash
php artisan pactivasign:keys
```

This writes `storage/pactivasign/client.jwk` and `storage/pactivasign/dpop.jwk` (mode 0600; keep them out of
git) and prints the public key. Your PactivaSign organization admin registers that public key as an OAuth
client and gives you its client ID. Then set:

```dotenv
PACTIVASIGN_BASE_URL=https://pactiva-sandbox-api.pactivasign.com
PACTIVASIGN_CLIENT_ID=pcl_...
PACTIVASIGN_SCOPES="documents:create documents:read documents:download signing:create signing:read"
```

Check the connection:

```bash
php artisan pactivasign:test
```

Every server must use the same two key files. If you deploy to several machines, store the keys as
secrets instead of files: put the JWKs in `PACTIVASIGN_CLIENT_KEY` and `PACTIVASIGN_DPOP_KEY` in an encrypted
`.env` (`php artisan env:encrypt`), or point `pactivasign.client_key.provider` at a class implementing
`PactivaSign\Key\KeyProvider` that signs in your KMS.

Tokens are cached in your default cache store (or `PACTIVASIGN_CACHE_STORE`), shared by every worker.

## Sending documents

```php
use PactivaSign\Laravel\Facades\PactivaSign;

$document = PactivaSign::documents()->upload('NDA', fopen(storage_path('nda.pdf'), 'rb'), 'nda.pdf');

$envelope = PactivaSign::envelopes()->create([
    'name' => 'NDA with Acme',
    'documentIds' => [$document->id],
    'signers' => [['email' => $customer->email, 'name' => $customer->name]],
    'externalId' => "order-{$order->id}",
], idempotencyKey: "order-{$order->id}-nda");
```

Or inject `PactivaSign\Client` and use `$client->envelopes->create(...)`. Every resource, model and option is
documented in the [SDK README](https://github.com/pactivasign/pactivasign-php#usage).

## Webhooks

1. Publish and run the migration (it stores each delivery once):

   ```bash
   php artisan vendor:publish --tag=pactivasign-migrations
   php artisan migrate
   ```

2. Add the route, in `routes/web.php` or `routes/api.php`. It verifies signatures and skips CSRF:

   ```php
   Route::pactivaSignWebhooks();                      // POST /pactivasign/webhook
   ```

3. Create the subscription for `https://your-app.example/pactivasign/webhook` and put its secret in `.env`:

   ```php
   $subscription = PactivaSign::webhookSubscriptions()->create([
       'url' => route('pactivasign.webhook'),
       'eventTypes' => ['envelope.completed', 'envelope.declined', 'recipient.signed'],
   ]);
   // PACTIVASIGN_WEBHOOK_SECRET = $subscription->secret
   ```

4. Listen for the events:

   ```php
   use PactivaSign\Laravel\Events\EnvelopeCompleted;
   use PactivaSign\Laravel\Facades\PactivaSign;

   Event::listen(function (EnvelopeCompleted $completed) {
       if ($completed->isStale()) {
           return;                                    // a newer event for this envelope was already received
       }
       PactivaSign::envelopes()
           ->downloadSigned($completed->envelopeId())
           ->saveTo(storage_path("contracts/{$completed->envelopeId()}.pdf"));
   });
   ```

Events: `EnvelopeSent`, `EnvelopeCompleted`, `EnvelopeDeclined`, `EnvelopeVoided`, `EnvelopeExpired`,
`RecipientDelivered`, `RecipientDeliveryFailed`, `RecipientViewed`, `RecipientSigned`, `RecipientDeclined`,
and `WebhookReceived` for every delivery (including types added later). `$event->event` is the typed
`PactivaSign\Model\WebhookEvent`.

How deliveries are handled:

- The endpoint answers 200 as soon as the delivery is stored. Listeners run from a queued job
  (`PACTIVASIGN_WEBHOOK_QUEUE_CONNECTION`, `PACTIVASIGN_WEBHOOK_QUEUE`); with the `sync` driver they run
  right after the response is sent.
- Each event ID is handled once; repeated deliveries are acknowledged and ignored.
- Deliveries can arrive out of order. `isStale()` is true when a newer version of the same envelope arrived
  first.
- If a listener throws, the job is retried and the events fire again, so make listeners idempotent.
- To rotate the secret, call `rotateSecret()`, set the new value as `PACTIVASIGN_WEBHOOK_SECRET` and the old
  one as `PACTIVASIGN_WEBHOOK_PREVIOUS_SECRET` until `previousSecretExpiresAt`.
- Stored deliveries older than 30 days are deleted by `php artisan model:prune` (schedule it daily).

`php artisan pactivasign:webhook-test --type=envelope.completed` sends a signed sample through your route,
locally, to check the wiring.

## Testing your app

```php
use PactivaSign\Laravel\Facades\PactivaSign;
use PactivaSign\Laravel\Testing\PactivaSignFake;
use PactivaSign\Laravel\Testing\RecordedRequest;

$fake = PactivaSign::fake([
    'POST /api/v1/envelopes' => $envelopeJson,          // returned as the response's data
    'GET /api/v1/envelopes/*' => fn (RecordedRequest $request) => [...],
]);

// ... run the code under test ...

$fake->assertSent(fn (RecordedRequest $r) => $r->is('POST /api/v1/envelopes') && $r->json()['name'] === 'NDA');
$fake->assertNotSent('DELETE *');

// Post a signed webhook to your route:
[$body, $server] = PactivaSignFake::webhook(['type' => 'envelope.completed', 'data' => ['envelopeId' => $id]]);
$this->call('POST', '/pactivasign/webhook', server: $server, content: $body)->assertOk();
```

Requests without a stub fail the test, so nothing reaches the real API.

## Development

```bash
composer install
composer check
```

The integration tests in `tests/Integration` run against a live API when the SDK's `PACTIVASIGN_*`
variables are set.

## Security

See [SECURITY.md](SECURITY.md).

## Licence

MIT. See [LICENSE](LICENSE).
