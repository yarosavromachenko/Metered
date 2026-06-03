<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Exception;

use RuntimeException;

/**
 * Base class for every failure that represents a broken domain rule.
 *
 * Domain exceptions describe what the business considers impossible — an
 * invoice finalized twice, a quantity below zero, a plan version changed after
 * use. They are deliberately distinct from infrastructure failures: a lost
 * database connection is not a domain event, and the two must not be caught by
 * the same handler.
 *
 * Extends RuntimeException rather than a framework base class, because nothing
 * under Domain may depend on Laravel.
 */
abstract class DomainException extends RuntimeException {}
