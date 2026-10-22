<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Events;

/** envelope.completed: everyone signed; the signed PDF can be downloaded. */
final class EnvelopeCompleted extends PactivaSignWebhookEvent
{
}
