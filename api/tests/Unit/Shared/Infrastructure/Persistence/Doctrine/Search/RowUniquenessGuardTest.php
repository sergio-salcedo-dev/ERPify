<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Query\Expr\Select;
use Doctrine\ORM\QueryBuilder;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\RowUniquenessGuard;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit lock for the Row Uniqueness Contract guard (AR5). It mocks the Doctrine boundary
 * (QueryBuilder / EntityManager / ClassMetadata) — the project allows mock-at-the-outer-boundary —
 * so the guard's decision is exercised in isolation, with no database and no fixture entities to
 * fight the dead-code rector: a fetch-joined TO-MANY association is a programmer error
 * ({@see LogicException}, never a 422); a TO-ONE join or no join is allowed.
 *
 * @internal
 */
#[CoversClass(RowUniquenessGuard::class)]
final class RowUniquenessGuardTest extends TestCase
{
    #[Test]
    public function itRejectsAFetchJoinedToManyAssociationAsAProgrammerError(): void
    {
        $guard = new RowUniquenessGuard();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Row Uniqueness Contract violated');

        $guard->assert($this->queryBuilderFetchJoining(collectionValued: true));
    }

    #[Test]
    public function itAllowsAFetchJoinedToOneAssociation(): void
    {
        // A to-one fetch-join never multiplies rows: assert() must complete without throwing.
        $this->expectNotToPerformAssertions();

        (new RowUniquenessGuard())->assert($this->queryBuilderFetchJoining(collectionValued: false));
    }

    #[Test]
    public function itAllowsAQueryWithNoFetchJoin(): void
    {
        $this->expectNotToPerformAssertions();

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRootAliases')->willReturn(['o']);
        $queryBuilder->method('getRootEntities')->willReturn([stdClass::class]);
        $queryBuilder->method('getDQLPart')->willReturnMap([
            ['select', [new Select(['o'])]],
            ['join', []],
        ]);

        (new RowUniquenessGuard())->assert($queryBuilder);
    }

    /**
     * Builds a query builder whose `addSelect('i')` fetch-joins `o.items`; the association's
     * cardinality is whatever {@see ClassMetadata::isCollectionValuedAssociation()} reports.
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function queryBuilderFetchJoining(bool $collectionValued): QueryBuilder
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('isCollectionValuedAssociation')->willReturn($collectionValued);
        $metadata->method('getAssociationTargetClass')->willReturn(stdClass::class);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRootAliases')->willReturn(['o']);
        $queryBuilder->method('getRootEntities')->willReturn([stdClass::class]);
        $queryBuilder->method('getEntityManager')->willReturn($entityManager);
        $queryBuilder->method('getDQLPart')->willReturnMap([
            ['select', [new Select(['o', 'i'])]],
            ['join', ['o' => [new Join(Join::LEFT_JOIN, 'o.items', 'i')]]],
        ]);

        return $queryBuilder;
    }
}
