<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use Metered\Webhooks\Application\Delivery\WebhookTransport;
use Metered\Webhooks\Domain\Delivery\AttemptResult;
use Metered\Webhooks\Domain\Destination\PublicAddress;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use RuntimeException;

/**
 * SSRF guard plus HTTP client (ADR-0011). The host is resolved once; if any
 * address is not public nothing is sent, otherwise curl is pinned to the
 * checked address (no DNS rebinding). No proxy, no redirects, 5s connect /
 * 10s total, response read up to 1 MB and 1 KB kept. Sent requests are client
 * spans with `traceparent` (ADR-0012).
 */
final readonly class GuardedTransport implements WebhookTransport
{
    /** Reading stops here. */
    public const int MAX_ANSWER_BYTES = 1_048_576;

    private ClientInterface $client;

    private Tracing $tracing;

    /**
     * @param ClientInterface|null $client defaults to a cURL client: Guzzle's
     *                                     stream handler cannot pin the address
     */
    public function __construct(
        private Resolver $resolver,
        ?ClientInterface $client = null,
        private ?TrustedDestination $trusted = null,
        private float $connectTimeout = 5.0,
        private float $timeout = 10.0,
        ?Tracing $tracing = null,
    ) {
        $this->client = $client ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]);
        $this->tracing = $tracing ?? Tracing::disabled();
    }

    public function send(EndpointUrl $url, array $headers, string $body): AttemptResult
    {
        $address = $this->checkedAddress($url->host, $url->port);

        if ($address instanceof AttemptResult) {
            return $address;
        }

        $span = $this->tracing->tracer()
            ->spanBuilder('POST')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.request.method', 'POST')
            ->setAttribute('server.address', $url->host)
            ->setAttribute('server.port', $url->port)
            ->startSpan();
        $scope = $span->activate();

        try {
            $result = $this->request($url, $address, $headers + $this->tracing->carrier(), $body);
        } finally {
            $scope->detach();
        }

        if ($result->statusCode !== null) {
            $span->setAttribute('http.response.status_code', $result->statusCode);
        }

        // 4xx is an error for a client span too.
        if ($result->statusCode === null || $result->statusCode >= 400) {
            $span->setStatus(StatusCode::STATUS_ERROR, $result->error ?? 'HTTP ' . $result->statusCode);
        }

        $span->end();

        return $result;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(EndpointUrl $url, string $address, array $headers, string $body): AttemptResult
    {
        $started = hrtime(true);

        try {
            $response = $this->client->request('POST', $url->value, [
                RequestOptions::HEADERS => $headers,
                RequestOptions::BODY => $body,
                RequestOptions::ALLOW_REDIRECTS => false,
                // No proxy: it would resolve the host itself.
                RequestOptions::PROXY => '',
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
                RequestOptions::TIMEOUT => $this->timeout,
                RequestOptions::PROGRESS => static function (int $expected, int $received): void {
                    if ($received > self::MAX_ANSWER_BYTES) {
                        throw new RuntimeException(sprintf('The answer was longer than %d bytes and was not read further.', self::MAX_ANSWER_BYTES));
                    }
                },
                'curl' => [
                    CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $url->host, $url->port, str_contains($address, ':') ? '[' . $address . ']' : $address)],
                ],
            ]);

            $excerpt = $response->getBody()->read(AttemptResult::EXCERPT_LIMIT);

            return AttemptResult::responded($response->getStatusCode(), $this->since($started), $excerpt);
        } catch (GuzzleException|RuntimeException $e) {
            return AttemptResult::unreachable(mb_strcut($e->getMessage(), 0, 255), $this->since($started));
        }
    }

    private function checkedAddress(string $host, int $port): string|AttemptResult
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            return AttemptResult::unreachable(sprintf('%s does not resolve', $host), 0);
        }

        // The configured demo receiver: pinned, but not required to be public.
        if ($this->trusted instanceof TrustedDestination && $this->trusted->matches($host, $port)) {
            return $addresses[0];
        }

        // All addresses must be public, not just the first.
        foreach ($addresses as $address) {
            if (! PublicAddress::allows($address)) {
                return AttemptResult::refused(sprintf('%s resolves to %s, which webhooks may not reach', $host, $address));
            }
        }

        return $addresses[0];
    }

    private function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
