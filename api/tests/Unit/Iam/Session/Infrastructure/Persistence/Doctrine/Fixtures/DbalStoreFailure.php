<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Persistence\Doctrine\Fixtures;

use Doctrine\DBAL\Exception as DbalException;
use RuntimeException;

/**
 * A minimal concrete {@see DbalException} (the DBAL marker interface is empty) standing in for a store outage —
 * a lost connection or statement timeout — so the adapter's conversion can be exercised without a live database.
 */
final class DbalStoreFailure extends RuntimeException implements DbalException
{
}
