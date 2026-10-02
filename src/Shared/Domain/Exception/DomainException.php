<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Exception;

use RuntimeException;

/**
 * A broken business rule (an invoice finalized twice, a negative quantity), as
 * opposed to an infrastructure failure.
 */
abstract class DomainException extends RuntimeException {}
