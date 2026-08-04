<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Support\ConstructorCollaboratorTypes;
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

    public function testErasesTheIdentityAndItsResetTokens(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $tokens = new InMemoryPasswordResetTokenRepository($this->tokenFor(UserMother::DEFAULT_ID));

        $result = $this->useCase($users, $tokens)->execute(UserMother::DEFAULT_ID);

        $this->assertTrue($result->identityErased);
        $this->assertSame(1, $result->resetTokensDeleted);
        $this->assertTrue($users->removeCalled);
        $this->assertSame([UserMother::DEFAULT_ID], $tokens->deleteAllForUserCalls);
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

        $result = $this->useCase($users, $tokens)->execute(UserMother::DEFAULT_ID);

        $this->assertFalse($result->erasedAnything());
        $this->assertFalse($users->removeCalled);
    }

    public function testAMalformedSubjectIdIsRejectedBeforeAnyWork(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $useCase = $this->useCase(new InMemoryUserRepository(), $tokens);

        $this->expectException(InvalidUuidException::class);

        $useCase->execute('not-a-uuid');
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryPasswordResetTokenRepository $tokens,
    ): EraseIdentitySubject {
        return new EraseIdentitySubject($users, $tokens, new InlineTransactionManager());
    }

    private function tokenFor(string $userId): PasswordResetToken
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-14T13:00:00+00:00'));

        return PasswordResetToken::issue(self::TOKEN_ID, $userId, $generated->token);
    }
}
