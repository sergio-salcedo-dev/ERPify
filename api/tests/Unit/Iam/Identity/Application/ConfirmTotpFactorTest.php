<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Iam\Identity\Application\ConfirmTotpFactor;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Application\SendSecondFactorEnabledEmailBestEffort;
use Erpify\Iam\Identity\Application\TotpSecretVault;
use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\InvalidSecondFactorCode;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorAlreadyEnabled;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorIntegrityFailure;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorNotEnrolled;
use Erpify\Iam\Identity\Infrastructure\Security\Rfc6238TotpAlgorithm;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\TotpFactorMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingSessionSubjectLock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Crypto\InMemoryKeystore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Confirming an enrolment: a code computed from the sealed secret enables the factor and becomes its last
 * accepted step, then every session of the identity is revoked and the owner is mailed once, after the commit;
 * every refusal mutates nothing, revokes nothing and mails nobody.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the arrange names the real algorithm and envelope, the two
 *                                                   repositories, the session teardown, the mail, the clock and the
 *                                                   outcomes the cases assert
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per outcome of the confirmation
 */
#[CoversClass(ConfirmTotpFactor::class)]
#[CoversClass(TotpSecretVault::class)]
final class ConfirmTotpFactorTest extends TestCase
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    private const string NOW = '2041-03-07T10:15:20+00:00';

    private const string SECRET = 'twenty-bytes-secret!';

    private InMemoryKeystore $keystore;

    private TotpSecretVault $vault;

    private InMemorySessionRepository $sessions;

    private InlineTransactionManager $transactions;

    private RecordingSecondFactorEnabledEmailSender $emails;

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
        $this->keystore = new InMemoryKeystore();
        $this->vault = new TotpSecretVault(new SodiumEnvelopeEncryptor(new ScopeKeyring($this->keystore, self::KEK)));
        $this->sessions = new InMemorySessionRepository(
            SessionMother::active(id: '0190f600-0000-7000-8000-0000000000a1', userId: UserMother::DEFAULT_ID),
            SessionMother::active(id: '0190f600-0000-7000-8000-0000000000a2', userId: UserMother::DEFAULT_ID),
        );
        $this->transactions = new InlineTransactionManager();
        $this->emails = new RecordingSecondFactorEnabledEmailSender(
            sample: fn (): bool => $this->transactions->committed && !$this->transactions->inside,
        );
    }

    #[Test]
    public function aValidCodeEnablesTheFactorAndBecomesItsLastAcceptedStep(): void
    {
        $factor = $this->sealedPendingFactor();
        $factors = new InMemoryTotpFactorRepository($factor);
        $audit = new RecordingAuditLogger();

        $confirmed = $this->useCase($factors, $audit)
            ->enableWithCode(UserMother::DEFAULT_ID, $this->codeAt($this->step()))
        ;

        $this->assertSame($factor, $confirmed);
        $this->assertTrue($factor->isEnabled());
        $this->assertSame($this->step(), $factor->lastAcceptedStep());
        $this->assertSame(self::NOW, $factor->enabledAt()?->format(DateTimeInterface::ATOM));
        $this->assertSame([$factor], $factors->saved);
        $this->assertCount(1, $audit->records);
        $this->assertSame('SECOND_FACTOR_ENABLED', $audit->records[0]['action']);
        $this->assertSame([], $audit->records[0]['metadata']);
    }

    #[Test]
    public function anActivationRevokesEverySessionAndMailsTheOwnerOnceAfterTheCommit(): void
    {
        $this->useCase(new InMemoryTotpFactorRepository($this->sealedPendingFactor()))
            ->enableWithCode(UserMother::DEFAULT_ID, $this->codeAt($this->step()))
        ;

        // Every session, the asking device's included: the controller signs that one back in, as a password
        // change does.
        $this->assertSame([UserMother::DEFAULT_ID], $this->sessions->revokeAllCalls);
        $this->assertSame([UserMother::DEFAULT_EMAIL], $this->emails->sentTo);
        $this->assertSame([true], $this->emails->samples, 'the mail must leave after the activation committed');
    }

    #[Test]
    public function aFailingMailDoesNotUndoTheActivation(): void
    {
        $factor = $this->sealedPendingFactor();
        $this->emails = new RecordingSecondFactorEnabledEmailSender(new RuntimeException('smtp is down'));

        $this->useCase(new InMemoryTotpFactorRepository($factor))
            ->enableWithCode(UserMother::DEFAULT_ID, $this->codeAt($this->step()))
        ;

        $this->assertTrue($factor->isEnabled());
        $this->assertSame([UserMother::DEFAULT_ID], $this->sessions->revokeAllCalls);
    }

    #[Test]
    public function aPendingEnrolmentOlderThanItsWindowAnswersLikeNoEnrolment(): void
    {
        $factor = TotpFactorMother::pendingFor(
            sealedSecret: $this->vault->seal(TotpFactorMother::DEFAULT_SCOPE_ID, UserMother::DEFAULT_ID, self::SECRET),
            enrolmentStartedAt: new DateTimeImmutable('2041-03-07T10:05:20+00:00'),
        );

        try {
            $this->useCase(new InMemoryTotpFactorRepository($factor))
                ->enableWithCode(UserMother::DEFAULT_ID, $this->codeAt($this->step()))
            ;
            $this->fail('A pending enrolment ten minutes old must not be confirmed, even with a valid code.');
        } catch (SecondFactorNotEnrolled) {
            $this->assertFalse($factor->isEnabled());
            $this->assertNothingFollowedTheRefusal();
        }
    }

    #[Test]
    public function aPendingEnrolmentInsideItsWindowIsConfirmed(): void
    {
        $factor = TotpFactorMother::pendingFor(
            sealedSecret: $this->vault->seal(TotpFactorMother::DEFAULT_SCOPE_ID, UserMother::DEFAULT_ID, self::SECRET),
            enrolmentStartedAt: new DateTimeImmutable('2041-03-07T10:05:21+00:00'),
        );

        $this->useCase(new InMemoryTotpFactorRepository($factor))
            ->enableWithCode(UserMother::DEFAULT_ID, $this->codeAt($this->step()))
        ;

        $this->assertTrue($factor->isEnabled());
    }

    #[Test]
    public function aNonActiveIdentityIsWalledAndNothingIsWritten(): void
    {
        $factor = $this->sealedPendingFactor();
        $factors = new InMemoryTotpFactorRepository($factor);
        $audit = new RecordingAuditLogger();
        $user = UserMother::create();
        $user->deactivate();
        $user->pullDomainEvents();

        try {
            $this->useCase($factors, $audit, $user)
                ->enableWithCode(UserMother::DEFAULT_ID, $this->codeAt($this->step()))
            ;
            $this->fail('A deactivated identity must not enable a factor.');
        } catch (AccountDeactivated) {
            $this->assertFalse($factor->isEnabled());
            $this->assertSame([], $factors->saved);
            $this->assertSame([], $audit->records);
            $this->assertNothingFollowedTheRefusal();
        }
    }

    #[Test]
    public function aCodeFromTheNextStepIsAcceptedForClockDrift(): void
    {
        $factor = $this->sealedPendingFactor();

        $this->useCase(new InMemoryTotpFactorRepository($factor))
            ->enableWithCode(UserMother::DEFAULT_ID, $this->codeAt($this->step() + 1))
        ;

        $this->assertSame($this->step() + 1, $factor->lastAcceptedStep());
    }

    #[Test]
    public function aWrongCodeIsRefusedAndItsFailureIsCountedAndCommitted(): void
    {
        $factor = $this->sealedPendingFactor();
        $factors = new InMemoryTotpFactorRepository($factor);
        $audit = new RecordingAuditLogger();
        $wrong = '000000' === $this->codeAt($this->step()) ? '111111' : '000000';

        $this->expectException(InvalidSecondFactorCode::class);

        try {
            $this->useCase($factors, $audit)->enableWithCode(UserMother::DEFAULT_ID, $wrong);
        } finally {
            $this->assertFalse($factor->isEnabled());
            // Saved and committed BEFORE the refusal is thrown: a throw inside the transaction would roll the
            // count back with it, and the cap would never be reached.
            $this->assertSame(1, $factor->failedConfirmAttempts());
            $this->assertSame([$factor], $factors->saved);
            $this->assertTrue($this->transactions->committed, 'the counted failure must commit');
            $this->assertSame([], $audit->records);
            $this->assertNothingFollowedTheRefusal();
        }
    }

    #[Test]
    public function afterFiveWrongCodesEvenTheRightOneAnswersLikeNoEnrolment(): void
    {
        $factor = $this->sealedPendingFactor();
        $factors = new InMemoryTotpFactorRepository($factor);
        $wrong = '000000' === $this->codeAt($this->step()) ? '111111' : '000000';

        for ($attempt = 1; $attempt <= TotpFactor::MAX_FAILED_CONFIRMATIONS; ++$attempt) {
            try {
                $this->useCase($factors)->enableWithCode(UserMother::DEFAULT_ID, $wrong);
                $this->fail('A wrong code must be refused.');
            } catch (InvalidSecondFactorCode) {
                $this->assertSame($attempt, $factor->failedConfirmAttempts());
            }
        }

        try {
            $this->useCase($factors)->enableWithCode(UserMother::DEFAULT_ID, $this->codeAt($this->step()));
            $this->fail('An enrolment out of codes must not be confirmed, even with the right one.');
        } catch (SecondFactorNotEnrolled) {
            $this->assertFalse($factor->isEnabled());
            $this->assertSame(TotpFactor::MAX_FAILED_CONFIRMATIONS, $factor->failedConfirmAttempts());
            $this->assertNothingFollowedTheRefusal();
        }
    }

    #[Test]
    public function aCodeTwoStepsOldIsRefused(): void
    {
        $factor = $this->sealedPendingFactor();

        $this->expectException(InvalidSecondFactorCode::class);

        try {
            $this->useCase(new InMemoryTotpFactorRepository($factor))
                ->enableWithCode(UserMother::DEFAULT_ID, $this->codeAt($this->step() - 2))
            ;
        } finally {
            $this->assertFalse($factor->isEnabled());
        }
    }

    #[Test]
    public function withNoEnrolmentThereIsNothingToConfirm(): void
    {
        $this->expectException(SecondFactorNotEnrolled::class);

        $this->useCase(new InMemoryTotpFactorRepository())->enableWithCode(UserMother::DEFAULT_ID, '123456');
    }

    #[Test]
    public function anEnabledFactorIsNotConfirmedTwice(): void
    {
        $this->expectException(SecondFactorAlreadyEnabled::class);

        $this->useCase(new InMemoryTotpFactorRepository(TotpFactorMother::enabledFor()))
            ->enableWithCode(UserMother::DEFAULT_ID, '123456')
        ;
    }

    #[Test]
    public function aFactorWhoseKeyIsGoneIsAnIntegrityFailureNotAWrongCode(): void
    {
        $factor = $this->sealedPendingFactor();
        $this->keystore->destroy(TotpSecretVault::scopeOf($factor->scopeId()), DestroyReason::Erasure);

        try {
            $this->useCase(new InMemoryTotpFactorRepository($factor))
                ->enableWithCode(UserMother::DEFAULT_ID, $this->codeAt($this->step()))
            ;
            $this->fail('A factor whose key is destroyed must not be confirmed.');
        } catch (SecondFactorIntegrityFailure $secondFactorIntegrityFailure) {
            $this->assertInstanceOf(DekDestroyed::class, $secondFactorIntegrityFailure->getPrevious());
            $this->assertFalse($factor->isEnabled());
        }
    }

    #[Test]
    public function aSecretSealedForAnotherIdentityDoesNotOpen(): void
    {
        // The associated data binds the ciphertext to its identity: a sealed value copied onto another
        // identity's row must not authenticate there.
        $sealedForSomeoneElse = $this->vault->seal(
            TotpFactorMother::DEFAULT_SCOPE_ID,
            '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a99',
            self::SECRET,
        );
        $factor = TotpFactorMother::pendingFor(sealedSecret: $sealedForSomeoneElse);

        try {
            $this->useCase(new InMemoryTotpFactorRepository($factor))
                ->enableWithCode(UserMother::DEFAULT_ID, $this->codeAt($this->step()))
            ;
            $this->fail('A secret sealed for another identity must not open.');
        } catch (SecondFactorIntegrityFailure $secondFactorIntegrityFailure) {
            $this->assertInstanceOf(DecryptionFailed::class, $secondFactorIntegrityFailure->getPrevious());
            $this->assertFalse($factor->isEnabled());
        }
    }

    private function assertNothingFollowedTheRefusal(): void
    {
        $this->assertSame([], $this->sessions->revokeAllCalls, 'a refused confirmation revokes no session');
        $this->assertSame([], $this->emails->sentTo, 'and mails nobody');
    }

    private function sealedPendingFactor(): TotpFactor
    {
        return TotpFactorMother::pendingFor(
            sealedSecret: $this->vault->seal(TotpFactorMother::DEFAULT_SCOPE_ID, UserMother::DEFAULT_ID, self::SECRET),
        );
    }

    private function step(): int
    {
        return \intdiv((new DateTimeImmutable(self::NOW))->getTimestamp(), Rfc6238TotpAlgorithm::PERIOD_SECONDS);
    }

    private function codeAt(int $step): string
    {
        return (new Rfc6238TotpAlgorithm())->codeAt(self::SECRET, $step);
    }

    private function useCase(
        InMemoryTotpFactorRepository $factors,
        RecordingAuditLogger $audit = new RecordingAuditLogger(),
        ?User $user = null,
    ): ConfirmTotpFactor {
        $clock = FixedClock::at(self::NOW);

        return new ConfirmTotpFactor(
            new InMemoryUserRepository($user ?? UserMother::create()),
            $factors,
            new Rfc6238TotpAlgorithm(),
            $this->vault,
            $audit,
            new RevokeSessionsBestEffort(
                new RevokeAllSessions(
                    $this->sessions,
                    new RecordingEventBus(),
                    new InlineTransactionManager(),
                    $clock,
                    new RecordingSessionSubjectLock(),
                ),
                new NullLogger(),
            ),
            new SendSecondFactorEnabledEmailBestEffort($this->emails, new NullLogger()),
            $this->transactions,
            $clock,
        );
    }
}
