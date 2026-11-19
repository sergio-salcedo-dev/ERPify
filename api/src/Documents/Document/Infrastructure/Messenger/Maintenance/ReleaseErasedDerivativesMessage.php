<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

/**
 * One tick of the derivative release sweep; carries nothing, the sweep reads what is due.
 */
final readonly class ReleaseErasedDerivativesMessage
{
}
