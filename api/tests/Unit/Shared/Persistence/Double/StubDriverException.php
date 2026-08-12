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
 * Named rather than an anonymous `new class` so the driver exception has one definition for every test
 * that needs a real DBAL failure to translate, instead of a copy per call site.
 *
 * @internal
 */
final class StubDriverException extends AbstractException
{
}
