<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Testing;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PactivaSign\Client;
use PactivaSign\Http\RetryPolicy;
use PactivaSign\Key\LocalKey;
use PactivaSign\Webhook\Webhook;
use PHPUnit\Framework\Assert;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PactivaSign client for tests: no network, recorded requests, scripted answers.
 *
 *     $fake = PactivaSign::fake([
 *         'POST /api/v1/envelopes' => $envelopeJson,                       // wrapped as {"success": true, "data": ...}
 *         'GET /api/v1/envelopes/*' => fn (RecordedRequest $r) => [...],   // computed per request
 *         'DELETE *' => PactivaSignFake::response(['success' => true]),   // a full response body
 *     ]);
 *     ...
 *     $fake->assertSent(fn (RecordedRequest $r) => $r->is('POST /api/v1/envelopes') && $r->json()['name'] === 'NDA');
 */
final class PactivaSignFake implements ClientInterface
{
    /** @var list<RecordedRequest> */
    private array $recorded = [];

    private readonly Client $client;

    /**
     * @param array<string, mixed> $stubs
     */
    public function __construct(private array $stubs = [])
    {
        $factory = new HttpFactory();
        $this->client = new Client(
            environment: 'https://pactivasign.fake',
            clientId: 'pcl_fake',
            clientKey: LocalKey::generate('ES256', 'fake'),
            dpopKey: LocalKey::generate('ES256'),
            scopes: ['documents:read'],
            httpClient: $this,
            requestFactory: $factory,
            streamFactory: $factory,
            retryPolicy: RetryPolicy::none(),
        );
    }

    /**
     * A full response, for stubs that need a status, headers or a body without the success envelope.
     *
     * @param array<mixed>|string $body
     * @param array<string, string> $headers
     */
    public static function response(array|string $body = ['success' => true], int $status = 200, array $headers = []): ResponseInterface
    {
        if (is_array($body)) {
            $headers += ['Content-Type' => 'application/json'];
            $body = json_encode($body, JSON_THROW_ON_ERROR);
        }

        return new Response($status, $headers + ['X-Request-Id' => 'fake-request'], $body);
    }

    /**
     * A body and headers for posting a signed delivery to your webhook route in a test:
     *
     *     [$body, $headers] = PactivaSignFake::webhook(['type' => 'envelope.completed', ...]);
     *     $this->call('POST', '/pactivasign/webhook', server: $headers, content: $body);
     *
     * @param array<string, mixed> $event merged over a valid envelope.completed event
     * @return array{string, array<string, string>} the raw body, and server variables for the call
     */
    public static function webhook(array $event = [], ?string $secret = null): array
    {
        $body = json_encode(array_replace_recursive([
            'id' => 'evt_' . bin2hex(random_bytes(8)),
            'type' => 'envelope.completed',
            'occurredAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'organizationId' => '00000000-0000-4000-8000-000000000001',
            'data' => [
                'envelopeId' => '00000000-0000-4000-8000-000000000002',
                'version' => 1,
                'status' => 'COMPLETED',
                'externalId' => null,
                'metadata' => [],
            ],
        ], $event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $secret ??= config('pactivasign.webhooks.secret');

        return [$body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PACTIVA_SIGNATURE' => Webhook::signatureHeader($body, is_string($secret) ? $secret : ''),
        ]];
    }

    public function client(): Client
    {
        return $this->client;
    }

    /**
     * @param array<string, mixed> $stubs
     */
    public function stub(array $stubs): self
    {
        $this->stubs = $stubs + $this->stubs;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (str_ends_with($request->getUri()->getPath(), '/oauth/token')) {
            return self::response(['access_token' => 'fake-token', 'token_type' => 'DPoP', 'expires_in' => 3600, 'scope' => 'documents:read']);
        }

        $recorded = new RecordedRequest($request);
        $this->recorded[] = $recorded;
        foreach ($this->stubs as $pattern => $answer) {
            if ($recorded->is($pattern)) {
                if ($answer instanceof \Closure) {
                    $answer = $answer($recorded);
                }

                return $answer instanceof ResponseInterface ? $answer : self::response(['success' => true, 'data' => $answer]);
            }
        }

        Assert::fail(sprintf('PactivaSign::fake() has no stub for %s %s.', $recorded->method(), $recorded->path()));
    }

    /**
     * @return list<RecordedRequest>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * @param string|\Closure(RecordedRequest): bool $match a "METHOD /path" pattern or a predicate
     */
    public function assertSent(string|\Closure $match, int $times = 1): void
    {
        $count = count(array_filter($this->recorded, $this->matcher($match)));
        Assert::assertSame($times, $count, sprintf('Expected %d matching PactivaSign request(s), got %d.', $times, $count));
    }

    /**
     * @param string|\Closure(RecordedRequest): bool $match
     */
    public function assertNotSent(string|\Closure $match): void
    {
        $this->assertSent($match, 0);
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->recorded, 'Expected no PactivaSign requests.');
    }

    /**
     * @param string|\Closure(RecordedRequest): bool $match
     * @return \Closure(RecordedRequest): bool
     */
    private function matcher(string|\Closure $match): \Closure
    {
        return is_string($match) ? static fn (RecordedRequest $r) => $r->is($match) : $match;
    }
}
