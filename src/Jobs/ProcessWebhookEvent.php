<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use PactivaSign\Laravel\Events;
use PactivaSign\Laravel\Models\WebhookEventRecord;
use PactivaSign\Model\WebhookEventType;

/**
 * Turns a stored webhook delivery into Laravel events: WebhookReceived, then the type-specific event.
 */
final class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const EVENTS = [
        'envelope.sent' => Events\EnvelopeSent::class,
        'envelope.completed' => Events\EnvelopeCompleted::class,
        'envelope.declined' => Events\EnvelopeDeclined::class,
        'envelope.voided' => Events\EnvelopeVoided::class,
        'envelope.expired' => Events\EnvelopeExpired::class,
        'recipient.delivered' => Events\RecipientDelivered::class,
        'recipient.delivery_failed' => Events\RecipientDeliveryFailed::class,
        'recipient.viewed' => Events\RecipientViewed::class,
        'recipient.signed' => Events\RecipientSigned::class,
        'recipient.declined' => Events\RecipientDeclined::class,
    ];

    public function __construct(public readonly int $recordId)
    {
    }

    public function handle(Dispatcher $events): void
    {
        $record = WebhookEventRecord::query()->find($this->recordId);
        if ($record === null || $record->processed_at !== null) {
            return;
        }

        $event = $record->event();
        $events->dispatch(new Events\WebhookReceived($event, $record->stale));
        $class = self::EVENTS[$event->type->value] ?? null;
        if ($class !== null && $event->type !== WebhookEventType::Unknown) {
            $events->dispatch(new $class($event, $record->stale));
        }

        $record->processed_at = now();
        $record->save();
    }
}
