<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Events;

/** envelope.expired: the envelope expired before everyone signed. */
final class EnvelopeExpired extends PactivaSignWebhookEvent
{
}
