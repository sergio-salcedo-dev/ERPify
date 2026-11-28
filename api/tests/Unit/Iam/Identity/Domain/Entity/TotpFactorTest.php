<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorAlreadyEnabled;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\TotpFactorMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per rule of the aggregate
 */
#[CoversClass(TotpFactor::class)]
final class TotpFactorTest extends TestCase
{
    private const string ENROLMENT_STARTED_AT = '2041-03-07T10:00:00+00:00';

    #[Test]
    public function anEnrolmentIsPendingAndCarriesOnlyTheCiphertext(): void
    {
        $factor = TotpFactorMother::pendingFor();

        $this->assertFalse($factor->isEnabled());
        $this->assertNull($factor->enabledAt());
        $this->assertNull($factor->lastAcceptedStep());
        $this->assertSame(TotpFactorMother::PLACEHOLDER_CIPHERTEXT, $factor->sealedSecret());
        $this->assertSame(TotpFactorMother::DEFAULT_SCOPE_ID, $factor->scopeId());
    }

    #[Test]
    public function theOwnerAndTheScopeAreHeldInTheirCanonicalSpelling(): void
    {
        // Keystore scopes compare exactly, so the spelling that seals has to be the spelling that destroys.
        $factor = TotpFactorMother::pendingFor(
            userId: \strtoupper(UserMother::DEFAULT_ID),
            scopeId: \strtoupper(TotpFactorMother::DEFAULT_SCOPE_ID),
        );

        $this->assertSame(UserMother::DEFAULT_ID, $factor->userId());
        $this->assertSame(TotpFactorMother::DEFAULT_SCOPE_ID, $factor->scopeId());
    }

    #[Test]
    public function enablingRecordsTheInstantAndTheAcceptedStep(): void
    {
        $factor = TotpFactorMother::pendingFor();
        $at = new DateTimeImmutable('2041-03-07T10:15:20+00:00');

        $factor->enable(75_000_000, $at);

        $this->assertTrue($factor->isEnabled());
        $this->assertSame($at, $factor->enabledAt());
        $this->assertSame(75_000_000, $factor->lastAcceptedStep());
    }

    #[Test]
    public function anEnabledFactorCannotBeEnabledAgain(): void
    {
        $factor = TotpFactorMother::enabledFor();

        $this->expectException(SecondFactorAlreadyEnabled::class);

        $factor->enable(75_000_001, new DateTimeImmutable('2041-03-07T10:15:50+00:00'));
    }

    #[Test]
    public function aPendingEnrolmentLapsesTenMinutesAfterItStarted(): void
    {
        $factor = TotpFactorMother::pendingFor(enrolmentStartedAt: new DateTimeImmutable(self::ENROLMENT_STARTED_AT));

        $this->assertFalse($factor->pendingEnrolmentClosedAt(new DateTimeImmutable('2041-03-07T10:09:59+00:00')));
        $this->assertTrue($factor->pendingEnrolmentClosedAt(new DateTimeImmutable('2041-03-07T10:10:00+00:00')));
    }

    #[Test]
    public function aRestartReplacesTheSecretKeepsTheScopeAndRestartsTheWindow(): void
    {
        $factor = TotpFactorMother::pendingFor(enrolmentStartedAt: new DateTimeImmutable(self::ENROLMENT_STARTED_AT));
        $restartedAt = new DateTimeImmutable('2041-03-07T10:30:00+00:00');

        $factor->restartEnrolment('another-ciphertext', $restartedAt);

        $this->assertSame('another-ciphertext', $factor->sealedSecret());
        $this->assertSame(TotpFactorMother::DEFAULT_SCOPE_ID, $factor->scopeId(), 'the scope survives a restart');
        $this->assertFalse($factor->isEnabled());
        $this->assertFalse($factor->pendingEnrolmentClosedAt(new DateTimeImmutable('2041-03-07T10:39:59+00:00')));
    }

    #[Test]
    public function aPendingEnrolmentClosesAtItsFifthWrongCodeAndARestartReopensIt(): void
    {
        $startedAt = new DateTimeImmutable(self::ENROLMENT_STARTED_AT);
        $factor = TotpFactorMother::pendingFor(enrolmentStartedAt: $startedAt);

        for ($wrong = 1; $wrong < TotpFactor::MAX_FAILED_CONFIRMATIONS; ++$wrong) {
            $factor->recordFailedConfirmation($startedAt);
            $this->assertFalse($factor->pendingEnrolmentClosedAt($startedAt), \sprintf('open after %d', $wrong));
        }

        $factor->recordFailedConfirmation($startedAt);
        $this->assertTrue($factor->pendingEnrolmentClosedAt($startedAt), 'closed at the fifth wrong code');

        $factor->restartEnrolment('another-ciphertext', $startedAt);

        $this->assertFalse($factor->pendingEnrolmentClosedAt($startedAt), 'a restart zeroes the count');

        for ($wrong = 1; $wrong < TotpFactor::MAX_FAILED_CONFIRMATIONS; ++$wrong) {
            $factor->recordFailedConfirmation($startedAt);
        }

        $this->assertFalse($factor->pendingEnrolmentClosedAt($startedAt), 'four wrong codes after it leave it open');
    }

    #[Test]
    public function anEnabledFactorRefusesToCountAConfirmationFailure(): void
    {
        $factor = TotpFactorMother::enabledFor();

        $this->expectException(SecondFactorAlreadyEnabled::class);

        $factor->recordFailedConfirmation(new DateTimeImmutable(self::ENROLMENT_STARTED_AT));
    }

    #[Test]
    public function anEnabledFactorNeverLapses(): void
    {
        $factor = TotpFactorMother::enabledFor();

        $this->assertFalse($factor->pendingEnrolmentClosedAt(new DateTimeImmutable('2099-01-01T00:00:00+00:00')));
    }

    #[Test]
    public function anEnabledFactorCannotBeRestarted(): void
    {
        $factor = TotpFactorMother::enabledFor();
        $sealed = $factor->sealedSecret();

        try {
            $factor->restartEnrolment('another-ciphertext', new DateTimeImmutable(self::ENROLMENT_STARTED_AT));
            $this->fail('An enabled factor must not be replaced.');
        } catch (SecondFactorAlreadyEnabled) {
            $this->assertSame($sealed, $factor->sealedSecret());
        }
    }

    #[Test]
    public function aMalformedScopeIsRefused(): void
    {
        $this->expectException(InvalidUuidException::class);

        TotpFactor::enrol(
            TotpFactorMother::DEFAULT_ID,
            UserMother::DEFAULT_ID,
            'not-a-uuid',
            'ciphertext',
            new DateTimeImmutable(self::ENROLMENT_STARTED_AT),
        );
    }
}
