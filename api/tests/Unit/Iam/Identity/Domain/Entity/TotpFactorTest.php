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
 */
#[CoversClass(TotpFactor::class)]
final class TotpFactorTest extends TestCase
{
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
    public function theOwnerIsHeldInItsCanonicalSpelling(): void
    {
        $factor = TotpFactorMother::pendingFor(userId: \strtoupper(UserMother::DEFAULT_ID));

        $this->assertSame(UserMother::DEFAULT_ID, $factor->userId());
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
    public function aPendingEnrolmentCanBeRestartedWithANewSecret(): void
    {
        $factor = TotpFactorMother::pendingFor();

        $factor->restartEnrolment('another-ciphertext');

        $this->assertSame('another-ciphertext', $factor->sealedSecret());
        $this->assertSame(TotpFactorMother::DEFAULT_SCOPE_ID, $factor->scopeId(), 'the scope survives a restart');
        $this->assertFalse($factor->isEnabled());
    }

    #[Test]
    public function anEnabledFactorCannotBeRestarted(): void
    {
        $factor = TotpFactorMother::enabledFor();
        $sealed = $factor->sealedSecret();

        try {
            $factor->restartEnrolment('another-ciphertext');
            $this->fail('An enabled factor must not be replaced.');
        } catch (SecondFactorAlreadyEnabled) {
            $this->assertSame($sealed, $factor->sealedSecret());
        }
    }

    #[Test]
    public function aMalformedScopeIsRefused(): void
    {
        $this->expectException(InvalidUuidException::class);

        TotpFactor::enrol(TotpFactorMother::DEFAULT_ID, UserMother::DEFAULT_ID, 'not-a-uuid', 'ciphertext');
    }
}
