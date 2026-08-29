<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

use Throwable;

/**
 * Marker every domain/application exception of this module implements, so an infrastructure
 * caller (the observability line) can read the {@see FailureCategory} uniformly without
 * a match/instanceof chain over the concrete exception classes.
 */
interface ImageProcessingException extends Throwable
{
    public function failureCategory(): FailureCategory;
}
