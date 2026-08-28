<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Support\ConstructorCollaboratorTypes;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\PasswordResetTokenMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\RecoverySecretMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EraseIdentitySubject::class)]
final class EraseIdentitySubjectTest extends TestCase
{
    private const string TOKEN_ID = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e21';

    public function testErasesTheIdentityItsResetTokensAndItsRecoverySecret(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tokens = new InMemoryPasswordResetTokenRepository(PasswordResetTokenMother::pendingFor(id: self::TOKEN_ID));
        $secrets = new InMemoryRecoverySecretRepository(RecoverySecretMother::mintedFor());

        $result = $this->useCase($users, $tokens, $secrets)->execute(UserMother::DEFAULT_ID);

        $this->assertTrue($result->identityErased);
        $this->assertSame(1, $result->resetTokensDeleted);
        // Asserted as a COUNT and not merely as "the call happened": the recovery secret is the one artefact
        // here with a ten-year TTL and no sweep behind it, so a delete that matched zero rows would leave a
        // person's id in place for a decade with nothing scheduled to notice.
        $this->assertSame(1, $result->recoverySecretsDeleted);
        $this->assertTrue($users->removeCalled);
        $this->assertSame([UserMother::DEFAULT_ID], $tokens->deleteAllForUserCalls);
        $this->assertSame([UserMother::DEFAULT_ID], $secrets->deleteAllForUserCalls);
    }

    public function testItHoldsNoAuditCollaboratorSoItCannotPersistAnIdentifierItCannotErase(): void
    {
        // The compliance entry names the subject as its audit resource, so writing it would persist the
        // person's real id — and clearing that copy needs the pseudonym the actor pass mints, which is not
        // reachable from here. The absence of the collaborator is what makes "this use case leaves no
        // identifier behind for someone else to remember" a property of the type rather than of its callers.
        // Over the type NAMES rather than over the string form of the type, because the string form of a
        // nullable parameter is `?Fqcn` and no equality with the FQCN matches it — so adding the collaborator
        // back as an optional `?AuditLogger $logger = null`, the ordinary way a dependency arrives without
        // breaking callers, would leave this green while the class could once again persist an identifier it
        // has no way of erasing.
        $collaborators = ConstructorCollaboratorTypes::of(EraseIdentitySubject::class);

        $this->assertNotContains(AuditLogger::class, $collaborators);
        // The sweep has to have found something, or a constructor read as empty passes this whatever it takes.
        $this->assertNotEmpty($collaborators, 'No constructor parameter type resolved, so the rule has no subject.');
    }

    public function testARerunWithNothingLeftErasesNothing(): void
    {
        $users = new InMemoryUserRepository();
        $tokens = new InMemoryPasswordResetTokenRepository();

        $result = $this->useCase($users, $tokens, new InMemoryRecoverySecretRepository())
            ->execute(UserMother::DEFAULT_ID)
        ;

        $this->assertFalse($result->erasedAnything());
        $this->assertFalse($users->removeCalled);
    }

    public function testAMalformedSubjectIdIsRejectedBeforeAnyWork(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $useCase = $this->useCase(new InMemoryUserRepository(), $tokens, new InMemoryRecoverySecretRepository());

        $this->expectException(InvalidUuidException::class);

        $useCase->execute('not-a-uuid');
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryPasswordResetTokenRepository $tokens,
        InMemoryRecoverySecretRepository $secrets,
    ): EraseIdentitySubject {
        return new EraseIdentitySubject($users, $tokens, $secrets, new InlineTransactionManager());
    }
}
