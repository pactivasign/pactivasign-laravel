<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Events;

/** recipient.delivery_failed: the invitation email could not be delivered. */
final class RecipientDeliveryFailed extends PactivaSignWebhookEvent
{
}
