<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Domain\ActorContext;
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
 * The three `CHECK` constraints of `audit_log` — the two on its actor discriminant and the one on its
 * level — asked of Postgres itself. DBAL neither models nor introspects a table-level `CHECK`, so
 * `make db.diff` cannot see them drift or vanish, and `ActorContext`/`AuditLevel` guard only the rows PHP
 * writes — raw SQL is what these constraints exist for. `AuditLogCheckEnumTokenGateTest` compares the same
 * token lists with the enums at unit time, from the migrations' literals; this test is the half that sees
 * the database actually carrying them.
 *
 * Every insert runs in a transaction that is rolled back, one row per case: a rejected statement aborts the
 * Postgres transaction it runs in, so two cases never share one.
 *
 * @internal
 */
#[CoversClass(AuditLogSchemaListener::class)]
final class AuditLogCheckConstraintFunctionalTest extends KernelTestCase
{
    private const string CHECK_VIOLATION = '23514';

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
    #[DataProvider('provideALegalActorIsAcceptedCases')]
    public function aLegalActorIsAccepted(string $actorType, bool $withActorId): void
    {
        $this->connection->beginTransaction();

        try {
            $this->assertSame(1, $this->insertActor($actorType, $withActorId));
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideALegalActorIsAcceptedCases(): iterable
    {
        yield 'user with an id' => ['user', true];
        yield 'api_key with an id' => ['api_key', true];
        yield 'anonymous without an id' => ['anonymous', false];
        yield 'system without an id' => ['system', false];
    }

    #[Test]
    #[DataProvider('provideAnIllegalActorIsRejectedByTheNamedConstraintCases')]
    public function anIllegalActorIsRejectedByTheNamedConstraint(
        string $actorType,
        bool $withActorId,
        string $constraint,
    ): void {
        $this->connection->beginTransaction();

        try {
            $this->insertActor($actorType, $withActorId);
            $this->fail(\sprintf(
                'Postgres accepted actor_type "%s" %s an actor_id.',
                $actorType,
                $withActorId ? 'with' : 'without',
            ));
        } catch (DriverException $driverException) {
            $this->assertSame(self::CHECK_VIOLATION, $driverException->getSQLState());
            $this->assertStringContainsString(\sprintf('"%s"', $constraint), $driverException->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * `robot` without an id violates BOTH constraints, and Postgres documents that it tests a row's `CHECK`
     * constraints in alphabetical order by name — so the presence check, `…_actor_id_…`, is the one that
     * reports it. The same token WITH an id satisfies the presence check and isolates the token check.
     *
     * @return iterable<string, array{string, bool, string}>
     */
    public static function provideAnIllegalActorIsRejectedByTheNamedConstraintCases(): iterable
    {
        $presence = AuditLogSchemaListener::ACTOR_ID_PRESENCE_CHECK;
        $token = AuditLogSchemaListener::ACTOR_TYPE_CHECK;

        yield 'user without an id' => ['user', false, $presence];
        yield 'api_key without an id' => ['api_key', false, $presence];
        yield 'anonymous with an id' => ['anonymous', true, $presence];
        yield 'system with an id' => ['system', true, $presence];
        yield 'unknown token with an id' => ['robot', true, $token];
        yield 'unknown token without an id' => ['robot', false, $presence];
        yield 'upper-cased token' => ['USER', true, $token];
    }

    #[Test]
    public function theTokenCheckAdmitsExactlyTheActorTypeCases(): void
    {
        $admitted = $this->quotedTokensOf(AuditLogSchemaListener::ACTOR_TYPE_CHECK);

        $cases = \array_map(static fn (ActorType $type): string => $type->value, ActorType::cases());
        \sort($cases);

        $this->assertSame(
            $cases,
            $admitted,
            'The tokens audit_log_actor_type_check admits have drifted from ActorType::cases(): a new case needs '
            . 'a migration that replaces the constraint (and, if it carries no id, the presence check too).',
        );
    }

    /**
     * The presence check names the id-less types; an id-carrying one satisfies it unchanged. A new id-less
     * case added to the token check alone would be refused at runtime with the token test green.
     */
    #[Test]
    public function thePresenceCheckNamesExactlyTheIdLessActorTypes(): void
    {
        $idLess = [ActorContext::anonymous()->type->value, ActorContext::system()->type->value];
        \sort($idLess);

        $this->assertSame(
            $idLess,
            $this->quotedTokensOf(AuditLogSchemaListener::ACTOR_ID_PRESENCE_CHECK),
            'The id-less tokens audit_log_actor_id_presence_check names have drifted from the id-less actors '
            . 'ActorContext mints: a migration must replace the constraint.',
        );
    }

    #[Test]
    public function everyAuditLevelIsAccepted(): void
    {
        foreach (AuditLevel::cases() as $level) {
            $this->connection->beginTransaction();

            try {
                $this->assertSame(1, $this->insertActor('system', false, $level->value), $level->value);
            } finally {
                $this->connection->rollBack();
            }
        }
    }

    /**
     * A legal actor on every row, so the level check is the only one that can report it.
     */
    #[Test]
    #[DataProvider('provideAnIllegalLevelIsRejectedByTheLevelCheckCases')]
    public function anIllegalLevelIsRejectedByTheLevelCheck(string $level): void
    {
        $this->connection->beginTransaction();

        try {
            $this->insertActor('system', false, $level);
            $this->fail(\sprintf('Postgres accepted level "%s".', $level));
        } catch (DriverException $driverException) {
            $this->assertSame(self::CHECK_VIOLATION, $driverException->getSQLState());
            $this->assertStringContainsString(
                \sprintf('"%s"', AuditLogSchemaListener::LEVEL_CHECK),
                $driverException->getMessage(),
            );
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnIllegalLevelIsRejectedByTheLevelCheckCases(): iterable
    {
        yield 'unknown token' => ['debug'];
        yield 'upper-cased token' => ['SECURITY'];
        yield 'empty token' => [''];
    }

    #[Test]
    public function theLevelCheckAdmitsExactlyTheAuditLevelCases(): void
    {
        $cases = \array_map(static fn (AuditLevel $level): string => $level->value, AuditLevel::cases());
        \sort($cases);

        $this->assertSame(
            $cases,
            $this->quotedTokensOf(AuditLogSchemaListener::LEVEL_CHECK),
            'The tokens audit_log_level_check admits have drifted from AuditLevel::cases(): a new case needs a '
            . 'migration that replaces the constraint, and the pruner a retention window for it.',
        );
    }

    /**
     * @return list<string> the quoted literals of the named `audit_log` CHECK, sorted
     */
    private function quotedTokensOf(string $constraint): array
    {
        $definition = $this->connection->fetchOne(
            'SELECT pg_get_constraintdef(c.oid) FROM pg_constraint c '
            . 'JOIN pg_class t ON t.oid = c.conrelid '
            . 'JOIN pg_namespace n ON n.oid = t.relnamespace '
            . "WHERE n.nspname = current_schema() AND t.relname = 'audit_log' AND c.contype = 'c' "
            . 'AND c.conname = :name',
            ['name' => $constraint],
        );

        $this->assertIsString($definition, \sprintf('audit_log must carry the CHECK "%s".', $constraint));

        \preg_match_all("/'([^']*)'/", $definition, $matches);
        $tokens = $matches[1];
        \sort($tokens);

        return $tokens;
    }

    private function insertActor(string $actorType, bool $withActorId, string $level = 'activity'): int
    {
        return (int) $this->connection->executeStatement(
            'INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, metadata, occurred_on) '
            . "VALUES (CAST(:id AS UUID), :level, 'CHECK_PROBED', :actorType, CAST(:actorId AS UUID), "
            . "CAST(:correlationId AS UUID), CAST('{}' AS JSONB), NOW())",
            [
                'id' => Uuid::generate(),
                'level' => $level,
                'actorType' => $actorType,
                'actorId' => $withActorId ? Uuid::generate() : null,
                'correlationId' => Uuid::generate(),
            ],
        );
    }
}
