<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Tracing;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Service, environment and a per-process instance id. Cumulative metrics need
 * one series per process; dashboards sum over instances.
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
