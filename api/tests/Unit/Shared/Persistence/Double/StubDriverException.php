<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence\Double;

use Doctrine\DBAL\Driver\AbstractException;

/**
 * Minimal named driver exception so a test can build a real
 * {@see \Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException} — whose constructor
 * (inherited from {@see \Doctrine\DBAL\Exception\DriverException}) requires a
 * {@see \Doctrine\DBAL\Driver\Exception} plus a nullable query.
 *
 * Named, never an anonymous `new class`: PDepend — which PHPMD runs under `make php.quality` — has aborted
 * on anonymous classes, and that gate is not part of the fast unit loop, so the failure surfaces late. One
 * definition for every test needing a real DBAL failure is the smaller reason to keep it here.
 *
 * @internal
 */
final class StubDriverException extends AbstractException
{
}
