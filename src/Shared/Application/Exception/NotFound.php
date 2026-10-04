<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Exception;

/**
 * Marker for any module's "no such thing"; answered as 404 with the message
 * shown to the caller.
 */
interface NotFound {}
