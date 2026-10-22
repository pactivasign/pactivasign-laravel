<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Events;

use PactivaSign\Model\WebhookEvent;

/**
 * Base of the Laravel events fired for verified PactivaSign webhooks.
 *
 * Deliveries can arrive out of order. isStale() is true when a newer event for the same envelope
 * (a higher data->version) arrived first; most listeners should then do nothing. When in doubt,
 * fetch the envelope: `PactivaSign::envelopes()->get($event->event->data->envelopeId)`.
 */
abstract class PactivaSignWebhookEvent
{
    public function __construct(
        public readonly WebhookEvent $event,
        public readonly bool $stale,
    ) {
    }

    public function isStale(): bool
    {
        return $this->stale;
    }

    public function envelopeId(): string
    {
        return $this->event->data->envelopeId;
    }
}
