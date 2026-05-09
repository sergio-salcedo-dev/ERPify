<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Exception;

use Erpify\Shared\Domain\Exception\Conflict;
use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\NotFound;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(DomainException::class)]
final class DomainExceptionTest extends TestCase
{
    public function testAccessorsReturnConstructorArguments(): void
    {
        $exception = new class ('t', 'x') extends DomainException implements NotFound, Conflict {
        };

        $this->assertSame('t', $exception->type());
        $this->assertSame('x', $exception->title());
        $this->assertSame([], $exception->context());
        $this->assertSame('x', $exception->getMessage());
    }

    public function testContextIsExposedThroughAccessor(): void
    {
        $context = ['bankId' => '01JABC', 'attempt' => 3];
        $type = 'bank-not-found';
        $title = 'Bank not found';
        $exception = new class ($type, $title, $context) extends DomainException implements NotFound {
        };

        $this->assertSame($context, $exception->context());
    }

    public function testMarkerOrderingFollowsImplementsClause(): void
    {
        $exception = new class ('t', 'x') extends DomainException implements NotFound, Conflict {
        };

        // class_implements() also surfaces Throwable/Stringable from \Exception. We pin the
        // *relative* order of our markers — that's the precedence the factory relies on.
        $markers = \array_values(\array_intersect(
            \class_implements($exception),
            [NotFound::class, Conflict::class],
        ));

        $message = 'class_implements must surface NotFound before Conflict, mirroring the implements clause.';
        $this->assertSame([NotFound::class, Conflict::class], $markers, $message);
    }

    public function testBaseClassIsAbstract(): void
    {
        $reflectionClass = new ReflectionClass(DomainException::class);

        $this->assertTrue($reflectionClass->isAbstract(), 'DomainException must be abstract.');
    }
}
