<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Exception;

/**
 * A command collides with what already exists — a code or a reference the
 * project already uses. Answered as 409; the message is shown to the caller.
 */
interface Conflict {}
