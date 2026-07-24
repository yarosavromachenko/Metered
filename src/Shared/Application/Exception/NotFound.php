<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Exception;

/**
 * Something a command names does not exist in the caller's project.
 *
 * A marker, implemented by an exception, so the HTTP layer can answer 404
 * for any module's "no such thing" without knowing the module. The message is
 * written for the caller and is shown to them.
 */
interface NotFound {}
