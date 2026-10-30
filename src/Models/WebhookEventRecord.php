<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use PactivaSign\Model\WebhookEvent;

/**
 * A webhook delivery as received: one row per event ID, so repeated deliveries are recognised.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $envelope_id
 * @property int|null $envelope_version
 * @property bool $stale
 * @property string $payload
 * @property \Carbon\CarbonInterface $received_at
 * @property \Carbon\CarbonInterface|null $processed_at
 */
final class WebhookEventRecord extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $table = 'pactivasign_webhook_events';

    protected $guarded = [];

    /**
     * A property, not the casts() method: Laravel 10 does not read casts().
     *
     * @var array<string, string>
     */
    protected $casts = [
        'stale' => 'boolean',
        'envelope_version' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function event(): WebhookEvent
    {
        $data = json_decode($this->payload, true, 512, JSON_THROW_ON_ERROR);

        return WebhookEvent::fromArray(is_array($data) ? $data : []);
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = config('pactivasign.webhooks.prune_after_days', 30);

        return self::query()->where('received_at', '<', now()->subDays(is_numeric($days) ? (int) $days : 30));
    }
}
