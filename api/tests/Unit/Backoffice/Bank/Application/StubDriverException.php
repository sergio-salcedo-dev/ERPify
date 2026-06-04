<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Doctrine\DBAL\Driver\AbstractException;

/**
 * Minimal named driver exception so a test can build a real
 * {@see \Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException} — whose constructor
 * (inherited from {@see \Doctrine\DBAL\Exception\DriverException}) requires a
 * {@see \Doctrine\DBAL\Driver\Exception} plus a nullable query.
 *
 * A named class (never an anonymous `new class`) is mandatory here: PDepend/PHPMD aborts on
 * anonymous classes during {@see make php.quality}.
 *
 * @internal
 */
final class StubDriverException extends AbstractException
{
}
