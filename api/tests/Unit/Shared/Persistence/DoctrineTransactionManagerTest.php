<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Persistence\Infrastructure\DoctrineTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DoctrineTransactionManager::class)]
final class DoctrineTransactionManagerTest extends TestCase
{
    #[Test]
    public function itRunsTheOperationInsideWrapInTransactionAndReturnsItsResult(): void
    {
        // A runtime-computed value (not a literal) so the round-trip assertion is a real check, not one
        // PHPStan narrows to an always-true literal comparison.
        $expected = \bin2hex(\random_bytes(8));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation())
        ;

        $result = (new DoctrineTransactionManager($entityManager))->transactional(static fn (): string => $expected);

        $this->assertSame($expected, $result);
    }
}
