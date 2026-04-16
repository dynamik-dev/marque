<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Exceptions;

use RuntimeException;

/**
 * Base exception for all Marque package errors.
 *
 * Consumers can catch this single type to handle any failure originating
 * from the Marque package without needing to enumerate every subtype.
 */
class MarqueException extends RuntimeException {}
