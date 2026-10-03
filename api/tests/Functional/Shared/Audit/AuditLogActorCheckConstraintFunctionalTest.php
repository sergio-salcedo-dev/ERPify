<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Persistence\AuditLogSchemaListener;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Holds the `audit_log` CHECK constraints to {@see AuditLogSchemaListener::checkConstraints()}, the one part
 * of that table's shape `MappedSchemaMatchesMigrationsTest` cannot see: DBAL's comparator models no `CHECK`, so
 * a migration that forgot one, or an `ActorType` case added without re-declaring the token list, would pass
 * every schema gate.
 *
 * Two readings, because each misses what the other sees. The catalog reading compares the live constraints with
 * the declared ones as Postgres itself normalises them — the declared expressions are attached to a temporary
 * twin of the table and both sets are read back through `pg_get_constraintdef()`, so a difference in spelling
 * that means the same thing is no difference, and a constraint present on one side only is. The behavioural
 * reading inserts every actor type with and without an id and requires a refusal exactly where
 * {@see ActorType::isIdentified()} says the shape is illegal, naming the constraint that refused it — so a
 * refusal from something else (a `NOT NULL`, a malformed value) cannot pass for this one.
 *
 * Every test runs inside a transaction that is always rolled back, so nothing it writes escapes.
 *
 * @internal
 */
#[CoversClass(AuditLogSchemaListener::class)]
final class AuditLogActorCheckConstraintFunctionalTest extends KernelTestCase
{
    private const string CHECK_VIOLATION = '23514';

    private const string TWIN = 'audit_log_check_twin';

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->connection = $entityManager->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function theTableCarriesExactlyTheDeclaredCheckConstraints(): void
    {
        // `LIKE` copies columns and NOT NULL but no CHECK, so the twin starts with none of its own.
        $this->connection->executeStatement(\sprintf('CREATE TEMPORARY TABLE %s (LIKE audit_log)', self::TWIN));

        foreach (AuditLogSchemaListener::checkConstraints() as $name => $expression) {
            $this->connection->executeStatement(
                \sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', self::TWIN, $name, $expression),
            );
        }

        $declared = $this->checkConstraintsOf(self::TWIN);
        $this->assertNotEmpty($declared, 'the listener declares no CHECK, so the comparison would be vacuous');

        $this->assertSame(
            $declared,
            $this->checkConstraintsOf('audit_log'),
            'audit_log no longer carries the CHECK constraints AuditLogSchemaListener::checkConstraints() '
            . 'declares. DBAL cannot generate a CHECK, so `make db.diff` will not write this migration: add one '
            . 'by hand that drops and re-adds the constraint with the declared expression, then '
            . '`make db.test.prepare`.',
        );
    }

    #[Test]
    #[DataProvider('provideARowIsAdmittedExactlyWhenItsActorIdAgreesWithItsTypeCases')]
    public function aRowIsAdmittedExactlyWhenItsActorIdAgreesWithItsType(ActorType $type, bool $withId): void
    {
        $refusedBy = $this->constraintRefusing($type->value, $withId ? Uuid::generate() : null);

        if ($type->isIdentified() === $withId) {
            $this->assertNull($refusedBy, 'a legal actor shape is admitted');

            return;
        }

        $this->assertSame(
            'audit_log_actor_id_presence_check',
            $refusedBy,
            $withId
                ? 'an actor that names nobody may not carry an id'
                : 'an identified actor may not be stored without its id — no erasure pass could reach the row',
        );
    }

    /** @return iterable<string, array{ActorType, bool}> */
    public static function provideARowIsAdmittedExactlyWhenItsActorIdAgreesWithItsTypeCases(): iterable
    {
        foreach (ActorType::cases() as $type) {
            yield $type->value . ' with an id' => [$type, true];
            yield $type->value . ' without an id' => [$type, false];
        }
    }

    #[Test]
    public function aTokenOutsideTheActorTypeEnumIsRefused(): void
    {
        // The uppercase spelling is what the wire enums use; storage round-trips the lowercase backing value.
        $this->assertSame(
            'audit_log_actor_type_check',
            $this->constraintRefusing(\strtoupper(ActorType::USER->value), Uuid::generate()),
        );
    }

    /** @return array<string, string> constraint name => definition as Postgres prints it */
    private function checkConstraintsOf(string $table): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint '
            . "WHERE conrelid = CAST(:table AS regclass) AND contype = 'c' ORDER BY conname",
            ['table' => $table],
        );

        $constraints = [];

        foreach ($rows as $name => $definition) {
            $this->assertIsString($name);
            $this->assertIsString($definition);
            $constraints[$name] = $definition;
        }

        return $constraints;
    }

    /** The name of the CHECK constraint that refused the row, or null when it was written. */
    private function constraintRefusing(string $actorType, ?string $actorId): ?string
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO audit_log '
                . '(id, level, action, actor_type, actor_id, correlation_id, metadata, actor_erased, '
                . 'resource_erased, occurred_on) '
                . "VALUES (CAST(:id AS UUID), :level, 'CHECK_CONSTRAINT_PROBE', :actor_type, "
                . "CAST(:actor_id AS UUID), CAST(:correlation_id AS UUID), '{}', FALSE, FALSE, NOW())",
                [
                    'id' => Uuid::generate(),
                    'level' => AuditLevel::SECURITY->value,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'correlation_id' => Uuid::generate(),
                ],
            );
        } catch (DriverException $driverException) {
            $this->assertSame(self::CHECK_VIOLATION, $driverException->getSQLState(), $driverException->getMessage());

            if (1 !== \preg_match('/violates check constraint "([^"]+)"/', $driverException->getMessage(), $match)) {
                $this->fail('a check violation that names no constraint: ' . $driverException->getMessage());
            }

            return $match[1];
        }

        return null;
    }
}
