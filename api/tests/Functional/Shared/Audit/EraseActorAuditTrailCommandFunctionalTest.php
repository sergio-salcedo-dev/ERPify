<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Cli\EraseActorAuditTrailCommand;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end proof that a real erasure self-audits off-request. The command-unit test proves the wiring
 * through a double; this drives the real {@see \Erpify\Shared\Audit\Application\AuditLogger} security path
 * from the CLI (no request in flight) against a real Postgres and asserts the compliance record actually
 * lands: exactly one `security` `GDPR_ERASURE_EXECUTED` row, sealed as a `system` actor, carrying the
 * resulting pseudonym — never the original id.
 *
 * Runs inside a transaction that is always rolled back.
 *
 * @internal
 */
#[CoversClass(EraseActorAuditTrailCommand::class)]
final class EraseActorAuditTrailCommandFunctionalTest extends KernelTestCase
{
    private const string ERASURE_ACTION = 'GDPR_ERASURE_EXECUTED';

    public function testARealErasureSelfAuditsOffRequestAsASystemSecurityEntry(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            // Isolate the assertion to this run's self-audit (the rollback also undoes this delete).
            $connection->executeStatement(
                'DELETE FROM audit_log WHERE action = :action',
                ['action' => self::ERASURE_ACTION],
            );

            $writer = new DbalAuditLogWriter($connection);
            $occurredOn = new DateTimeImmutable('2026-06-25T00:00:00+00:00');
            $subjectId = Uuid::generate();
            $this->seedForActor($writer, ActorContext::forUser($subjectId), $occurredOn);
            $this->seedForActor($writer, ActorContext::forUser($subjectId), $occurredOn);

            $exitCode = (new CommandTester($this->command()))
                ->execute(['actor-id' => $subjectId, '--force' => true])
            ;

            $this->assertSame(Command::SUCCESS, $exitCode);

            $entry = $this->onlyErasureEntry($connection);
            $this->assertSame(AuditLevel::SECURITY->value, $entry['level'] ?? null);
            $this->assertSame('system', $entry['actor_type'] ?? null, 'an off-request erasure is sealed as system');
            $this->assertNull($entry['actor_id'] ?? null, 'a system actor carries no actor id');

            $rawMetadata = $entry['metadata'] ?? null;
            $this->assertIsString($rawMetadata);
            $metadata = \json_decode($rawMetadata, true);
            $this->assertIsArray($metadata);
            $this->assertSame(2, $metadata['affected_rows'] ?? null);

            $pseudonym = $metadata['anonymized_actor_id'] ?? null;
            $this->assertIsString($pseudonym);
            $this->assertTrue(Uuid::isValid($pseudonym));
            $this->assertNotSame($subjectId, $pseudonym, 'the original id is never recorded');
        });
    }

    private function command(): EraseActorAuditTrailCommand
    {
        $command = self::getContainer()->get(EraseActorAuditTrailCommand::class);
        $this->assertInstanceOf(EraseActorAuditTrailCommand::class, $command);

        return $command;
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyErasureEntry(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT level, actor_type, actor_id, metadata FROM audit_log WHERE action = :action',
            ['action' => self::ERASURE_ACTION],
        );
        $this->assertCount(1, $rows, 'a real erasure self-audits exactly once');

        return \reset($rows);
    }

    private function seedForActor(DbalAuditLogWriter $writer, ActorContext $actor, DateTimeImmutable $occurredOn): void
    {
        $writer->write(AuditLogEntry::create(
            'BANK_ACCOUNTS_VIEWED',
            AuditLevel::ACTIVITY,
            $actor,
            Uuid::generate(),
            $occurredOn,
        ));
    }

    private function inRolledBackTransaction(callable $body): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);

        $connection->beginTransaction();

        try {
            $body($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
