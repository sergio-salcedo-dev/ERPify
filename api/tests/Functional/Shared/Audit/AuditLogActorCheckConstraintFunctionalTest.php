<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Audit\Infrastructure\Persistence\AuditLogSchemaListener;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The two `CHECK` constraints on the actor discriminant of `audit_log`, asked of Postgres itself. DBAL
 * neither models nor introspects a table-level `CHECK`, so `make db.diff` cannot see them drift or vanish,
 * and `ActorContext` guards only the rows PHP writes — raw SQL is what these constraints exist for.
 *
 * Every insert runs in a transaction that is rolled back, one row per case: a rejected statement aborts the
 * Postgres transaction it runs in, so two cases never share one.
 *
 * @internal
 */
#[CoversClass(AuditLogSchemaListener::class)]
final class AuditLogActorCheckConstraintFunctionalTest extends KernelTestCase
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

    private function insertActor(string $actorType, bool $withActorId): int
    {
        return (int) $this->connection->executeStatement(
            'INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, metadata, occurred_on) '
            . "VALUES (CAST(:id AS UUID), 'activity', 'ACTOR_CHECK_PROBED', :actorType, CAST(:actorId AS UUID), "
            . "CAST(:correlationId AS UUID), CAST('{}' AS JSONB), NOW())",
            [
                'id' => Uuid::generate(),
                'actorType' => $actorType,
                'actorId' => $withActorId ? Uuid::generate() : null,
                'correlationId' => Uuid::generate(),
            ],
        );
    }
}
