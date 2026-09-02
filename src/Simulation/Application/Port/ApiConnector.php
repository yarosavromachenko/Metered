<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

interface ApiConnector
{
    public function connect(string $token): MeteredApi;
}
