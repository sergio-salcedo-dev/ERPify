<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Iam\Identity\Application\ConfirmTotpFactor;
use Erpify\Iam\Identity\Application\TotpSecretVault;
use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Exception\InvalidSecondFactorCode;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorAlreadyEnabled;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorIntegrityFailure;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorNotEnrolled;
use Erpify\Iam\Identity\Infrastructure\Security\Rfc6238TotpAlgorithm;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\TotpFactorMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Crypto\InMemoryKeystore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Confirming an enrolment: a code computed from the sealed secret enables the factor and becomes its last
 * accepted step; everything else mutates nothing.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the arrange names the real algorithm and envelope, the two
 *                                                   repositories, the clock and the outcomes the cases assert
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

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at(self::NOW));
        $this->keystore = new InMemoryKeystore();
        $this->vault = new TotpSecretVault(new SodiumEnvelopeEncryptor(new ScopeKeyring($this->keystore, self::KEK)));
    }

    #[Test]
    public function aValidCodeEnablesTheFactorAndBecomesItsLastAcceptedStep(): void
    {
        $factor = $this->sealedPendingFactor();
        $factors = new InMemoryTotpFactorRepository($factor);
        $audit = new RecordingAuditLogger();

        $confirmed = $this->useCase($factors, $audit)->confirm(UserMother::DEFAULT_ID, $this->codeAt($this->step()));

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
    public function aCodeFromTheNextStepIsAcceptedForClockDrift(): void
    {
        $factor = $this->sealedPendingFactor();

        $this->useCase(new InMemoryTotpFactorRepository($factor))
            ->confirm(UserMother::DEFAULT_ID, $this->codeAt($this->step() + 1))
        ;

        $this->assertSame($this->step() + 1, $factor->lastAcceptedStep());
    }

    #[Test]
    public function aWrongCodeIsRefusedAndMutatesNothing(): void
    {
        $factor = $this->sealedPendingFactor();
        $factors = new InMemoryTotpFactorRepository($factor);
        $audit = new RecordingAuditLogger();
        $wrong = '000000' === $this->codeAt($this->step()) ? '111111' : '000000';

        $this->expectException(InvalidSecondFactorCode::class);

        try {
            $this->useCase($factors, $audit)->confirm(UserMother::DEFAULT_ID, $wrong);
        } finally {
            $this->assertFalse($factor->isEnabled());
            $this->assertSame([], $factors->saved);
            $this->assertSame([], $audit->records);
        }
    }

    #[Test]
    public function aCodeTwoStepsOldIsRefused(): void
    {
        $factor = $this->sealedPendingFactor();

        $this->expectException(InvalidSecondFactorCode::class);

        try {
            $this->useCase(new InMemoryTotpFactorRepository($factor))
                ->confirm(UserMother::DEFAULT_ID, $this->codeAt($this->step() - 2))
            ;
        } finally {
            $this->assertFalse($factor->isEnabled());
        }
    }

    #[Test]
    public function withNoEnrolmentThereIsNothingToConfirm(): void
    {
        $this->expectException(SecondFactorNotEnrolled::class);

        $this->useCase(new InMemoryTotpFactorRepository())->confirm(UserMother::DEFAULT_ID, '123456');
    }

    #[Test]
    public function anEnabledFactorIsNotConfirmedTwice(): void
    {
        $this->expectException(SecondFactorAlreadyEnabled::class);

        $this->useCase(new InMemoryTotpFactorRepository(TotpFactorMother::enabledFor()))
            ->confirm(UserMother::DEFAULT_ID, '123456')
        ;
    }

    #[Test]
    public function aFactorWhoseKeyIsGoneIsAnIntegrityFailureNotAWrongCode(): void
    {
        $factor = $this->sealedPendingFactor();
        $this->keystore->destroy(TotpSecretVault::scopeOf($factor->scopeId()), DestroyReason::Erasure);

        try {
            $this->useCase(new InMemoryTotpFactorRepository($factor))
                ->confirm(UserMother::DEFAULT_ID, $this->codeAt($this->step()))
            ;
            $this->fail('A factor whose key is destroyed must not be confirmed.');
        } catch (SecondFactorIntegrityFailure $failure) {
            $this->assertInstanceOf(DekDestroyed::class, $failure->getPrevious());
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
                ->confirm(UserMother::DEFAULT_ID, $this->codeAt($this->step()))
            ;
            $this->fail('A secret sealed for another identity must not open.');
        } catch (SecondFactorIntegrityFailure $failure) {
            $this->assertInstanceOf(DecryptionFailed::class, $failure->getPrevious());
            $this->assertFalse($factor->isEnabled());
        }
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
    ): ConfirmTotpFactor {
        return new ConfirmTotpFactor(
            new InMemoryUserRepository(UserMother::create()),
            $factors,
            new Rfc6238TotpAlgorithm(),
            $this->vault,
            $audit,
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
        );
    }
}
