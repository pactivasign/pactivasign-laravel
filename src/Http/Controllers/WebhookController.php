<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PactivaSign\Laravel\Jobs\ProcessWebhookEvent;
use PactivaSign\Laravel\Models\WebhookEventRecord;
use PactivaSign\Model\WebhookEvent;

/**
 * Stores each verified delivery once and answers 200 straight away. Listeners run from a queued job,
 * so a slow listener never makes PactivaSign retry the delivery.
 */
final class WebhookController
{
    public function __invoke(Request $request, Config $config): JsonResponse
    {
        $event = $request->attributes->get('pactivasign_event');
        if (!$event instanceof WebhookEvent) {
            return response()->json(['error' => 'unverified'], 400);
        }

        $version = (int) $event->data->version;
        $stale = WebhookEventRecord::query()
            ->where('envelope_id', $event->data->envelopeId)
            ->where('envelope_version', '>', $version)
            ->exists();

        try {
            $record = WebhookEventRecord::query()->create([
                'event_id' => $event->id,
                'type' => (string) ($event->toArray()['type'] ?? ''),
                'envelope_id' => $event->data->envelopeId,
                'envelope_version' => $version,
                'stale' => $stale,
                'payload' => $request->getContent(),
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A repeated delivery of an event we already have.
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $connection = $config->get('pactivasign.webhooks.queue_connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : $config->get('queue.default');
        $queue = $config->get('pactivasign.webhooks.queue');
        if ($connection === 'sync') {
            ProcessWebhookEvent::dispatchAfterResponse($record->id);
        } else {
            ProcessWebhookEvent::dispatch($record->id)
                ->onConnection(is_string($connection) ? $connection : null)
                ->onQueue(is_string($queue) && $queue !== '' ? $queue : null);
        }

        return response()->json(['received' => true, 'duplicate' => false]);
    }
}
