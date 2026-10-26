<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use PactivaSign\Laravel\Testing\PactivaSignFake;
use PactivaSign\Model\WebhookEventType;

final class WebhookTestCommand extends Command
{
    protected $signature = 'pactivasign:webhook-test
        {--type=envelope.completed : The event type to send}';

    protected $description = 'Send a signed sample webhook through your local PactivaSign webhook route, without network access';

    public function handle(): int
    {
        $type = is_string($this->option('type')) ? $this->option('type') : 'envelope.completed';
        if (WebhookEventType::tryFrom($type) === null) {
            $this->error("Unknown event type $type.");

            return self::FAILURE;
        }
        $router = $this->laravel->make('router');
        if (!$router->has('pactivasign.webhook')) {
            $this->error('No webhook route. Add Route::pactivaSignWebhooks(); to routes/web.php or routes/api.php.');

            return self::FAILURE;
        }

        if (!is_string(config('pactivasign.webhooks.secret')) || config('pactivasign.webhooks.secret') === '') {
            $this->error('No webhook secret. Set PACTIVASIGN_WEBHOOK_SECRET (any value works for this local test).');

            return self::FAILURE;
        }

        [$body, $server] = PactivaSignFake::webhook(['type' => $type]);
        $uri = route('pactivasign.webhook', absolute: false);
        $kernel = $this->laravel->make(Kernel::class);
        $request = Request::create($uri, 'POST', server: $server, content: $body);
        $response = $kernel->handle($request);
        // Runs work deferred until after the response, such as event dispatch on the sync queue.
        $kernel->terminate($request, $response);

        $this->line(sprintf('POST %s -> %d %s', $uri, $response->getStatusCode(), (string) $response->getContent()));

        return $response->getStatusCode() === 200 ? self::SUCCESS : self::FAILURE;
    }
}
