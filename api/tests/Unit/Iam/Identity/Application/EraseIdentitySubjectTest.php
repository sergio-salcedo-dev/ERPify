<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EraseIdentitySubject::class)]
final class EraseIdentitySubjectTest extends TestCase
{
    private const string TOKEN_ID = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e21';

    public function testErasesTheIdentityItsResetTokensAndSelfAuditsOnce(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tokens = new InMemoryPasswordResetTokenRepository($this->tokenFor(UserMother::DEFAULT_ID));
        $audit = new RecordingAuditLogger();

        $result = $this->useCase($users, $tokens, $audit)->execute(UserMother::DEFAULT_ID);

        $this->assertTrue($result->identityErased);
        $this->assertSame(1, $result->resetTokensDeleted);
        $this->assertTrue($users->removeCalled);
        $this->assertSame([UserMother::DEFAULT_ID], $tokens->deleteAllForUserCalls);
        $this->assertCount(1, $audit->records);
        $this->assertSame('GDPR_SUBJECT_ERASED', $audit->records[0]['action']);
        $this->assertSame(AuditLevel::SECURITY, $audit->records[0]['level']);
        // Evidence carries only the pseudonymous id — never the erased email.
        $this->assertSame(
            ['subject_user_id' => UserMother::DEFAULT_ID, 'reset_tokens_deleted' => 1],
            $audit->records[0]['metadata'],
        );
    }

    public function testARerunWithNothingLeftErasesNothingAndSkipsTheSelfAudit(): void
    {
        $users = new InMemoryUserRepository();
        $tokens = new InMemoryPasswordResetTokenRepository();
        $audit = new RecordingAuditLogger();

        $result = $this->useCase($users, $tokens, $audit)->execute(UserMother::DEFAULT_ID);

        $this->assertFalse($result->erasedAnything());
        $this->assertFalse($users->removeCalled);
        $this->assertSame([], $audit->records);
    }

    public function testAMalformedSubjectIdIsRejectedBeforeAnyWork(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $useCase = $this->useCase(new InMemoryUserRepository(), $tokens, new RecordingAuditLogger());

        $this->expectException(InvalidUuidException::class);

        $useCase->execute('not-a-uuid');
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryPasswordResetTokenRepository $tokens,
        RecordingAuditLogger $audit,
    ): EraseIdentitySubject {
        return new EraseIdentitySubject($users, $tokens, $audit, new InlineTransactionManager());
    }

    private function tokenFor(string $userId): PasswordResetToken
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-14T13:00:00+00:00'));

        return PasswordResetToken::issue(self::TOKEN_ID, $userId, $generated->token);
    }
}
