<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Persistence\Doctrine\Fixtures;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\RetryableException;
use RuntimeException;

/**
 * A minimal {@see DbalException} carrying DBAL's {@see RetryableException} marker — the shape a PostgreSQL
 * `40P01` deadlock arrives in — so the adapter's split between "retry the transaction" and "the store is down"
 * can be exercised without provoking a real deadlock.
 */
final class DbalDeadlock extends RuntimeException implements DbalException, RetryableException
{
}
