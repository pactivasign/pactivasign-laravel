<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use PactivaSign\Exception\SignatureVerificationException;
use PactivaSign\Webhook\Webhook;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects webhook requests without a valid, recent Pactiva-Signature with 400. The verified event
 * is put on the request as the `pactivasign_event` attribute.
 */
final class VerifyWebhookSignature
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $secrets = array_values(array_filter([
            $this->config->get('pactivasign.webhooks.secret'),
            $this->config->get('pactivasign.webhooks.previous_secret'),
        ], static fn ($secret) => is_string($secret) && $secret !== ''));
        if ($secrets === []) {
            $this->logger->error('PactivaSign webhook received, but pactivasign.webhooks.secret is not configured.');

            return response()->json(['error' => 'webhook secret not configured'], 500);
        }

        $tolerance = $this->config->get('pactivasign.webhooks.tolerance', Webhook::DEFAULT_TOLERANCE_SECONDS);
        try {
            $event = Webhook::verify(
                $request->getContent(),
                $request->headers->get(Webhook::SIGNATURE_HEADER) ?? '',
                $secrets,
                is_numeric($tolerance) ? (int) $tolerance : Webhook::DEFAULT_TOLERANCE_SECONDS,
            );
        } catch (SignatureVerificationException $e) {
            $this->logger->warning('PactivaSign webhook rejected: ' . $e->getMessage(), [
                'event_id' => $request->headers->get(Webhook::EVENT_ID_HEADER),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'invalid signature'], 400);
        }

        $request->attributes->set('pactivasign_event', $event);

        return $next($request);
    }
}
