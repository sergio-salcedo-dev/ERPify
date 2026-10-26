<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Application\RecordInvitationAudit;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RecordInvitationAudit::class)]
final class RecordInvitationAuditTest extends TestCase
{
    /**
     * The row names the person, so it is erasable only if it commits on one side of the erasure: it is written
     * after the identity's own row lock, inside the unit of work that holds it.
     */
    #[Test]
    #[DataProvider('acts')]
    public function itWritesASecurityRowNamingTheInviteeUnderTheIdentityRowLock(Closure $act, string $action): void
    {
        $users = new InMemoryUserRepository(UserMother::invited(UserMother::DEFAULT_ID));
        $transactions = new InlineTransactionManager();
        $auditLogger = new TransactionAwareAuditLogger($transactions);

        $act(new RecordInvitationAudit($users, $auditLogger, $transactions), UserMother::DEFAULT_ID);

        $this->assertSame([UserMother::DEFAULT_ID], $users->forUpdateCalls);
        $this->assertCount(1, $auditLogger->records);
        $this->assertSame($action, $auditLogger->records[0]['action']);
        $this->assertSame(AuditLevel::SECURITY, $auditLogger->records[0]['level']);
        $this->assertSame('User', $auditLogger->records[0]['resource']?->type);
        $this->assertSame(UserMother::DEFAULT_ID, $auditLogger->records[0]['resource']->id);
        $this->assertSame([], $auditLogger->records[0]['metadata']);
        $this->assertFalse($auditLogger->records[0]['committed']);
    }

    /**
     * Arriving after an erasure, the locked read finds no identity: the person is owed no row naming them.
     */
    #[Test]
    #[DataProvider('acts')]
    public function itWritesNothingForAnIdentityThatIsGone(Closure $act, string $action): void
    {
        $transactions = new InlineTransactionManager();
        $auditLogger = new TransactionAwareAuditLogger($transactions);

        $audit = new RecordInvitationAudit(new InMemoryUserRepository(), $auditLogger, $transactions);

        $act($audit, UserMother::DEFAULT_ID);

        $this->assertSame([], $auditLogger->records, $action . ' must name nobody who is gone.');
    }

    /**
     * @return iterable<string, array{0: Closure(RecordInvitationAudit, string): void, 1: string}>
     */
    public static function acts(): iterable
    {
        yield 'resent' => [
            static fn (RecordInvitationAudit $audit, string $userId) => $audit->resent($userId),
            'INVITATION_RESENT',
        ];

        yield 'revoked' => [
            static fn (RecordInvitationAudit $audit, string $userId) => $audit->revoked($userId),
            'INVITATION_REVOKED',
        ];
    }
}
