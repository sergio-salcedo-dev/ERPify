<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Query\Expr\Select;
use Doctrine\ORM\QueryBuilder;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\RowUniquenessGuard;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit lock for the Row Uniqueness Contract guard (AR5). It mocks the Doctrine boundary
 * (QueryBuilder / EntityManager / ClassMetadata) — the project allows mock-at-the-outer-boundary —
 * so the guard's decision is exercised in isolation, with no database and no fixture entities to
 * fight the dead-code rector. Three shapes are programmer errors ({@see LogicException}, never a 422):
 * a fetch-joined TO-MANY association, a TO-MANY join that is NOT selected (joined only for a filter
 * but multiplies rows under LIMIT just the same), and a multi-root cartesian product. A TO-ONE join,
 * a single root, or no join is allowed.
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

        $guard->assert($this->queryBuilderJoining(collectionValued: true, select: ['o', 'i']));
    }

    #[Test]
    public function itRejectsAnUnselectedToManyJoinAsAProgrammerError(): void
    {
        // The to-many is joined for a filter but never selected; it still multiplies SQL rows
        // under LIMIT, so the join — not the selection — is the discriminant.
        $guard = new RowUniquenessGuard();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Row Uniqueness Contract violated');

        $guard->assert($this->queryBuilderJoining(collectionValued: true, select: ['o']));
    }

    #[Test]
    public function itAllowsAFetchJoinedToOneAssociation(): void
    {
        // A to-one fetch-join never multiplies rows: assert() must complete without throwing.
        $this->expectNotToPerformAssertions();

        (new RowUniquenessGuard())->assert($this->queryBuilderJoining(collectionValued: false, select: ['o', 'i']));
    }

    #[Test]
    public function itRejectsMultipleFromRootsAsACartesianProduct(): void
    {
        // from(A)->from(B) cross-joins the roots and multiplies rows before any join is even considered.
        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRootAliases')->willReturn(['o', 'p']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Row Uniqueness Contract violated');

        (new RowUniquenessGuard())->assert($queryBuilder);
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
     * Builds a single-root query builder that joins `o.items` as alias `i`; the association's
     * cardinality is whatever {@see ClassMetadata::isCollectionValuedAssociation()} reports, and
     * `$select` controls whether the join is also pulled into the hydration.
     *
     * @param list<string> $select
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function queryBuilderJoining(bool $collectionValued, array $select): QueryBuilder
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
            ['select', [new Select($select)]],
            ['join', ['o' => [new Join(Join::LEFT_JOIN, 'o.items', 'i')]]],
        ]);

        return $queryBuilder;
    }
}
