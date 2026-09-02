<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Http;

use Illuminate\Http\Client\Factory;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Simulation\Application\Port\ApiConnector;
use Metered\Simulation\Application\Port\MeteredApi;

final readonly class HttpApiConnector implements ApiConnector
{
    public function __construct(
        private Factory $http,
        private IdentifierGenerator $ids,
        private string $baseUrl,
    ) {}

    public function connect(string $token): MeteredApi
    {
        return new HttpMeteredApi($this->http, $this->ids, $this->baseUrl, $token);
    }
}
