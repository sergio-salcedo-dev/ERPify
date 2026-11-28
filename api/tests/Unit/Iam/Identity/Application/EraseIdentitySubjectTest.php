<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\EraseIdentitySubject;
use Erpify\Iam\Identity\Application\TotpSecretVault;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\KeyState;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Support\ConstructorCollaboratorTypes;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\PasswordResetTokenMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\RecoverySecretMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\TotpFactorMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Crypto\InMemoryKeystore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the arrange names one double per table the erasure reaches,
 *                                                   plus the keystore whose tombstone it asserts
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

    public function testErasesTheSecondFactorAndDestroysTheKeyThatSealedIt(): void
    {
        $factors = new InMemoryTotpFactorRepository(TotpFactorMother::pendingFor());
        $keystore = new InMemoryKeystore();
        $scope = TotpSecretVault::scopeOf(TotpFactorMother::DEFAULT_SCOPE_ID);
        $keystore->store($scope, new WrappedDek('wrapped-key-material', 1));

        $this->assertSame(KeyState::Live, $keystore->lockState($scope), 'the seed must hold the key to destroy');

        $result = $this->useCase(
            new InMemoryUserRepository(UserMother::create()),
            new InMemoryPasswordResetTokenRepository(),
            new InMemoryRecoverySecretRepository(),
            $factors,
            $keystore,
        )->execute(UserMother::DEFAULT_ID);

        $this->assertSame(1, $result->totpFactorsDeleted);
        $this->assertCount(1, $factors->removed);
        $this->assertSame(KeyState::Tombstoned, $keystore->lockState($scope));
        $this->assertSame(DestroyReason::Erasure, $keystore->reasonFor($scope));
        // The scope travels to the compliance entry, which is what answers the tombstone. It is the factor's own
        // random scope, so it names nothing about the person.
        $this->assertSame($scope->toString(), $result->destroyedTotpScope);
        $this->assertStringNotContainsString(UserMother::DEFAULT_ID, (string) $result->destroyedTotpScope);
    }

    public function testASecondFactorWhoseKeyIsAlreadyTombstonedReportsNoScope(): void
    {
        $keystore = new InMemoryKeystore();
        $keystore->destroy(TotpSecretVault::scopeOf(TotpFactorMother::DEFAULT_SCOPE_ID), DestroyReason::Erasure);

        $result = $this->useCase(
            new InMemoryUserRepository(),
            new InMemoryPasswordResetTokenRepository(),
            new InMemoryRecoverySecretRepository(),
            new InMemoryTotpFactorRepository(TotpFactorMother::pendingFor()),
            $keystore,
        )->execute(UserMother::DEFAULT_ID);

        // The orphan row still leaves, and still counts as an erasure: a partial erasure re-run must finish.
        $this->assertSame(1, $result->totpFactorsDeleted);
        $this->assertTrue($result->erasedAnything());
        $this->assertNull($result->destroyedTotpScope, 'the tombstone was answered by the run that wrote it');
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
        InMemoryTotpFactorRepository $factors = new InMemoryTotpFactorRepository(),
        InMemoryKeystore $keystore = new InMemoryKeystore(),
    ): EraseIdentitySubject {
        return new EraseIdentitySubject($users, $tokens, $secrets, $factors, $keystore, new InlineTransactionManager());
    }
}
