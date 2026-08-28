<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use Metered\Webhooks\Application\Delivery\WebhookTransport;
use Metered\Webhooks\Domain\Delivery\AttemptResult;
use Metered\Webhooks\Domain\Destination\PublicAddress;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;
use RuntimeException;

/**
 * The SSRF guard and the HTTP client, in the one place a tenant's URL is ever
 * called (ADR-0011).
 *
 * The host is resolved once. If any address it resolves to is not public,
 * nothing is sent. Otherwise the connection is pinned to the address that was
 * checked — curl is told the host's address instead of asking DNS again — so
 * a DNS server that answers "public" to the check and "169.254.169.254" to
 * the connection gets nowhere. Redirects are not followed; a kilobyte of the
 * answer is kept and reading stops after a megabyte; connect and total
 * timeouts are 5s and 10s.
 */
final readonly class GuardedTransport implements WebhookTransport
{
    /** Past this, the answer is not read any further: a receiver cannot make a worker hold its reply in memory. */
    public const int MAX_ANSWER_BYTES = 1_048_576;

    private ClientInterface $client;

    /**
     * @param ClientInterface|null $client what sends the request — a client
     *                                     over cURL unless a test brings its
     *                                     own. Not Guzzle's default choice of
     *                                     handler: it may pick PHP's stream
     *                                     handler, which cannot be told which
     *                                     address to connect to, and pinning
     *                                     the checked address is the whole point.
     */
    public function __construct(
        private Resolver $resolver,
        ?ClientInterface $client = null,
        private ?TrustedDestination $trusted = null,
        private float $connectTimeout = 5.0,
        private float $timeout = 10.0,
    ) {
        $this->client = $client ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]);
    }

    public function send(EndpointUrl $url, array $headers, string $body): AttemptResult
    {
        $address = $this->checkedAddress($url->host, $url->port);

        if ($address instanceof AttemptResult) {
            return $address;
        }

        $started = hrtime(true);

        try {
            $response = $this->client->request('POST', $url->value, [
                RequestOptions::HEADERS => $headers,
                RequestOptions::BODY => $body,
                RequestOptions::ALLOW_REDIRECTS => false,
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

            // A kilobyte of the answer is kept; a longer one was cut off above.
            $excerpt = $response->getBody()->read(AttemptResult::EXCERPT_LIMIT);

            return AttemptResult::responded($response->getStatusCode(), $this->since($started), $excerpt);
        } catch (GuzzleException|RuntimeException $e) {
            return AttemptResult::unreachable(mb_strcut($e->getMessage(), 0, 255), $this->since($started));
        }
    }

    /**
     * The one address the request may go to, or the refusal to send it.
     */
    private function checkedAddress(string $host, int $port): string|AttemptResult
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            return AttemptResult::unreachable(sprintf('%s does not resolve', $host), 0);
        }

        // The local demo receiver, named exactly in configuration: resolved
        // and pinned like any other host, just not asked to be public.
        if ($this->trusted instanceof TrustedDestination && $this->trusted->matches($host, $port)) {
            return $addresses[0];
        }

        // Every address, not just the first: a host with one public and one
        // private record would otherwise be a coin toss away from the network.
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
