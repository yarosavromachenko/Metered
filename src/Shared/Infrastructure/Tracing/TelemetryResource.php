<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Tracing;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Who is reporting: the service, the environment, and this process.
 *
 * The instance id is per process and shared by its traces and metrics.
 * Metrics are cumulative per process, so each process must be a series of
 * its own — two workers reporting the same counter under one identity would
 * overwrite each other's totals. Dashboards sum over the instances.
 */
final class TelemetryResource
{
    private static ?string $instanceId = null;

    public static function describe(string $serviceName, string $deploymentEnvironment): ResourceInfo
    {
        self::$instanceId ??= bin2hex(random_bytes(8));

        return ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => $serviceName,
            ResourceAttributes::SERVICE_INSTANCE_ID => self::$instanceId,
            ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => $deploymentEnvironment,
        ])));
    }
}
