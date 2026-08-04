<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

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
        $collaborators = (new ReflectionClass(EraseIdentitySubject::class))
            ->getConstructor()?->getParameters() ?? []
        ;

        $types = \array_map(
            static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $collaborators,
        );

        $this->assertNotContains(\Erpify\Shared\Audit\Application\AuditLogger::class, $types);
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
