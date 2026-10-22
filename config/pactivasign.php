<?php

declare(strict_types=1);

return [

    /*
    | The PactivaSign API origin. Tokens are cached per environment.
    */
    'base_url' => env('PACTIVASIGN_BASE_URL', 'https://pactiva-api.duckdns.org'),

    /*
    | The OAuth client ID (pcl_...) your organization admin registered, and the scopes to request.
    | The scopes must be a subset of the scopes the client was granted.
    */
    'client_id' => env('PACTIVASIGN_CLIENT_ID'),

    'scopes' => array_values(array_filter(explode(' ', (string) env(
        'PACTIVASIGN_SCOPES',
        'documents:create documents:read documents:download signing:create signing:read',
    )))),

    /*
    | Two different private keys. Generate both with `php artisan pactivasign:keys`.
    |
    | Each key is read from the first of these that is set:
    | - provider: a container class implementing PactivaSign\Key\KeyProvider (e.g. a KMS signer)
    | - jwk: the private JWK itself, e.g. from an encrypted .env (php artisan env:encrypt)
    | - path: a JWK or PEM file; keep it at mode 0600 and out of version control
    */
    'client_key' => [
        'provider' => null,
        'jwk' => env('PACTIVASIGN_CLIENT_KEY'),
        'path' => env('PACTIVASIGN_CLIENT_KEY_PATH', storage_path('pactivasign/client.jwk')),
    ],

    'dpop_key' => [
        'provider' => null,
        'jwk' => env('PACTIVASIGN_DPOP_KEY'),
        'path' => env('PACTIVASIGN_DPOP_KEY_PATH', storage_path('pactivasign/dpop.jwk')),
    ],

    /*
    | Cache store for access tokens and DPoP nonces, shared by every worker. Null uses the default
    | store. Use one that supports atomic locks (redis, memcached, database, dynamodb, file).
    */
    'cache_store' => env('PACTIVASIGN_CACHE_STORE'),

    'webhooks' => [

        /*
        | The subscription's signing secret. During a rotation set the previous one too, until
        | previousSecretExpiresAt has passed.
        */
        'secret' => env('PACTIVASIGN_WEBHOOK_SECRET'),
        'previous_secret' => env('PACTIVASIGN_WEBHOOK_PREVIOUS_SECRET'),

        /* Deliveries signed longer ago than this are rejected, against replays. */
        'tolerance' => 300,

        /*
        | Queue for turning stored deliveries into Laravel events. Null uses the default connection.
        | With the sync driver, events are dispatched after the response has been sent.
        */
        'queue_connection' => env('PACTIVASIGN_WEBHOOK_QUEUE_CONNECTION'),
        'queue' => env('PACTIVASIGN_WEBHOOK_QUEUE'),

        /* Stored deliveries are pruned after this many days by `php artisan model:prune`. */
        'prune_after_days' => 30,
    ],

];
