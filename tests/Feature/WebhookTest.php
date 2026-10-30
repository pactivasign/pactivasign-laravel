<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PactivaSign\Laravel\Events\EnvelopeCompleted;
use PactivaSign\Laravel\Events\RecipientSigned;
use PactivaSign\Laravel\Events\WebhookReceived;
use PactivaSign\Laravel\Jobs\ProcessWebhookEvent;
use PactivaSign\Laravel\Models\WebhookEventRecord;
use PactivaSign\Laravel\Testing\PactivaSignFake;
use PactivaSign\Laravel\Tests\TestCase;
use PactivaSign\Model\EnvelopeStatus;
use PactivaSign\Model\WebhookEventType;
use PactivaSign\Webhook\Webhook;

final class WebhookTest extends TestCase
{
    public function testAVerifiedDeliveryIsStoredAndBecomesLaravelEvents(): void
    {
        Event::fake([WebhookReceived::class, EnvelopeCompleted::class]);
        [$body, $server] = PactivaSignFake::webhook(['id' => 'evt_1', 'data' => ['version' => 4, 'externalId' => 'deal-42']]);

        $response = $this->call('POST', '/pactivasign/webhook', server: $server, content: $body);

        $response->assertOk()->assertExactJson(['received' => true, 'duplicate' => false]);
        $record = WebhookEventRecord::query()->sole();
        self::assertSame('evt_1', $record->event_id);
        self::assertSame('envelope.completed', $record->type);
        self::assertSame(4, $record->envelope_version);
        self::assertSame($body, $record->payload);
        self::assertFalse($record->stale);
        self::assertInstanceOf(\Carbon\CarbonInterface::class, $record->received_at);
        self::assertInstanceOf(\Carbon\CarbonInterface::class, $record->processed_at);
        Event::assertDispatched(WebhookReceived::class, 1);
        Event::assertDispatched(EnvelopeCompleted::class, function (EnvelopeCompleted $event): bool {
            return $event->event->id === 'evt_1'
                && $event->event->type === WebhookEventType::EnvelopeCompleted
                && $event->event->data->status === EnvelopeStatus::Completed
                && $event->event->data->externalId === 'deal-42'
                && !$event->isStale();
        });
    }

    public function testTheRouteSkipsCsrfProtectionOfTheWebGroup(): void
    {
        // Laravel turns CSRF checks off while running tests; switch them back on.
        $this->app['env'] = 'local';
        \Illuminate\Support\Facades\Route::middleware('web')->post('/csrf-protected', fn () => 'ok');
        [$body, $server] = PactivaSignFake::webhook();

        $this->call('POST', '/csrf-protected', server: $server, content: $body)->assertStatus(419);
        $this->call('POST', '/pactivasign/webhook', server: $server, content: $body)->assertOk();
    }

    public function testRepeatedDeliveriesAreAcknowledgedButHandledOnce(): void
    {
        Event::fake([WebhookReceived::class]);
        [$body, $server] = PactivaSignFake::webhook(['id' => 'evt_dup']);

        $this->call('POST', '/pactivasign/webhook', server: $server, content: $body)->assertOk();
        $this->call('POST', '/pactivasign/webhook', server: $server, content: $body)
            ->assertOk()
            ->assertJson(['duplicate' => true]);

        self::assertSame(1, WebhookEventRecord::query()->count());
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
    }

    public function testOlderEventsArrivingLateAreMarkedStale(): void
    {
        Event::fake([RecipientSigned::class, EnvelopeCompleted::class]);
        [$newer, $s1] = PactivaSignFake::webhook(['id' => 'evt_new', 'data' => ['version' => 5]]);
        [$older, $s2] = PactivaSignFake::webhook(['id' => 'evt_old', 'type' => 'recipient.signed', 'data' => [
            'version' => 3,
            'status' => 'IN_PROGRESS',
            'recipient' => ['id' => 'r1', 'status' => 'SIGNED', 'version' => 3],
        ]]);

        $this->call('POST', '/pactivasign/webhook', server: $s1, content: $newer)->assertOk();
        $this->call('POST', '/pactivasign/webhook', server: $s2, content: $older)->assertOk();

        Event::assertDispatched(EnvelopeCompleted::class, fn (EnvelopeCompleted $e) => !$e->isStale());
        Event::assertDispatched(RecipientSigned::class, fn (RecipientSigned $e) => $e->isStale() && $e->event->data->recipient?->id === 'r1');
    }

    public function testInvalidSignaturesAreRejected(): void
    {
        Event::fake();
        [$body, $server] = PactivaSignFake::webhook([], 'whsec_someone_else');

        $this->call('POST', '/pactivasign/webhook', server: $server, content: $body)->assertStatus(400);
        $this->call('POST', '/pactivasign/webhook', server: ['CONTENT_TYPE' => 'application/json'], content: $body)->assertStatus(400);

        self::assertSame(0, WebhookEventRecord::query()->count());
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testTheBodyIsVerifiedExactlyAsSent(): void
    {
        [$body, $server] = PactivaSignFake::webhook();
        $reformatted = json_encode(json_decode($body, true), JSON_PRETTY_PRINT);

        $this->call('POST', '/pactivasign/webhook', server: $server, content: (string) $reformatted)->assertStatus(400);
    }

    public function testThePreviousSecretIsAcceptedDuringARotation(): void
    {
        config(['pactivasign.webhooks.secret' => 'whsec_new', 'pactivasign.webhooks.previous_secret' => self::WEBHOOK_SECRET]);
        [$body, $server] = PactivaSignFake::webhook([], self::WEBHOOK_SECRET);

        $this->call('POST', '/pactivasign/webhook', server: $server, content: $body)->assertOk();
    }

    public function testOldSignaturesAreRejectedAsReplays(): void
    {
        [$body] = PactivaSignFake::webhook();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_PACTIVA_SIGNATURE' => Webhook::signatureHeader($body, self::WEBHOOK_SECRET, time() - 301)];

        $this->call('POST', '/pactivasign/webhook', server: $server, content: $body)->assertStatus(400);
    }

    public function testAMissingSecretIsAServerError(): void
    {
        config(['pactivasign.webhooks.secret' => null]);
        [$body, $server] = PactivaSignFake::webhook([], 'anything');

        $this->call('POST', '/pactivasign/webhook', server: $server, content: $body)->assertStatus(500);
    }

    public function testUnknownEventTypesOnlyFireWebhookReceived(): void
    {
        Event::fake();
        [$body, $server] = PactivaSignFake::webhook(['type' => 'envelope.archived']);

        $this->call('POST', '/pactivasign/webhook', server: $server, content: $body)->assertOk();

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e) => $e->event->type === WebhookEventType::Unknown);
        Event::assertNotDispatched(EnvelopeCompleted::class);
    }

    public function testDeliveriesAreQueuedOnAConfiguredQueue(): void
    {
        Queue::fake();
        config(['pactivasign.webhooks.queue_connection' => 'redis', 'pactivasign.webhooks.queue' => 'webhooks']);
        [$body, $server] = PactivaSignFake::webhook();

        $this->call('POST', '/pactivasign/webhook', server: $server, content: $body)->assertOk();

        Queue::assertPushedOn('webhooks', ProcessWebhookEvent::class);
        self::assertNull(WebhookEventRecord::query()->sole()->processed_at, 'listeners run later, from the queue');
    }

    public function testOldDeliveriesArePruned(): void
    {
        [$body, $server] = PactivaSignFake::webhook();
        $this->call('POST', '/pactivasign/webhook', server: $server, content: $body)->assertOk();
        WebhookEventRecord::query()->update(['received_at' => now()->subDays(31)]);

        $this->artisan('model:prune', ['--model' => [WebhookEventRecord::class]])->assertSuccessful();

        self::assertSame(0, WebhookEventRecord::query()->count());
    }
}
