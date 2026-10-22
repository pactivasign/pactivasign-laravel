<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Events;

/** Every verified delivery, whatever its type, including types added after this release. */
final class WebhookReceived extends PactivaSignWebhookEvent
{
}
