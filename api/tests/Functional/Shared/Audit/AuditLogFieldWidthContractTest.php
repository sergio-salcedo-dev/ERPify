<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClassConstant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `AuditLogEntry` refuses an over-long `action` or `resource_type` against a constant that mirrors the
 * `VARCHAR` width of the matching `audit_log` column, so the caller gets an actionable error instead of a
 * failing INSERT. The two sides are coupled by nothing but intent and either can move alone: widen the
 * column and the guard rejects values Postgres would accept, narrow it and the guard hands the INSERT
 * exactly the value it exists to keep away from it.
 *
 * Postgres is the authority, not the migration that created the table — an `ALTER` applied out of band
 * still counts. The query reads `information_schema` and writes nothing.
 *
 * @internal
 */
#[CoversClass(AuditLogEntry::class)]
final class AuditLogFieldWidthContractTest extends KernelTestCase
{
    private const string TABLE = 'audit_log';

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->connection = $entityManager->getConnection();
    }

    #[Test]
    #[DataProvider('provideEachGuardedColumnIsAsWideAsTheEntryGuardCases')]
    public function eachGuardedColumnIsAsWideAsTheEntryGuard(string $column): void
    {
        $width = $this->connection->fetchOne(
            'SELECT character_maximum_length FROM information_schema.columns '
            // `table_schema` is part of the identity of a column: without it a second schema holding a table
            // of the same name makes this a single-row read over an ambiguous set, and `fetchOne` would take
            // whichever row the planner returned first.
            . 'WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column',
            ['table' => self::TABLE, 'column' => $column],
        );

        $this->assertIsNumeric(
            $width,
            \sprintf('%s.%s must exist and be a width-bounded character type.', self::TABLE, $column),
        );
        $this->assertSame(
            $this->guardedFieldLength(),
            (int) $width,
            \sprintf(
                '%s.%s and AuditLogEntry::MAX_FIELD_LENGTH must move together.',
                self::TABLE,
                $column,
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEachGuardedColumnIsAsWideAsTheEntryGuardCases(): iterable
    {
        yield 'action' => ['action'];
        yield 'resource_type' => ['resource_type'];
    }

    /**
     * The bound is private because no collaborator may size a field by it — the entry is the only place
     * that decides. Reading it by reflection makes the contract checkable without widening that surface.
     */
    private function guardedFieldLength(): int
    {
        $length = (new ReflectionClassConstant(AuditLogEntry::class, 'MAX_FIELD_LENGTH'))->getValue();
        $this->assertIsInt($length);

        return $length;
    }
}
