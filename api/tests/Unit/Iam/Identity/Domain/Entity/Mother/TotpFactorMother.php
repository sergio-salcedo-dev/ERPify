<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Shared\Clock\Domain\SystemClock;

/**
 * A {@see TotpFactor} for tests that need one to exist rather than to exercise how it was sealed. The sealed
 * secret is a placeholder: nothing opens it unless a test seals a real one. A pending factor is enrolled at the
 * ambient clock's instant unless told otherwise, so it is inside its confirmation window for any test that does not
 * move that clock.
 */
final class TotpFactorMother
{
    public const string DEFAULT_ID = '0190f500-0000-7000-8000-0000000000f1';

    public const string DEFAULT_SCOPE_ID = '0190f500-0000-7000-8000-0000000000f2';

    public const string PLACEHOLDER_CIPHERTEXT = 'not-a-real-ciphertext';

    public static function pendingFor(
        string $userId = UserMother::DEFAULT_ID,
        string $scopeId = self::DEFAULT_SCOPE_ID,
        string $sealedSecret = self::PLACEHOLDER_CIPHERTEXT,
        ?DateTimeImmutable $enrolmentStartedAt = null,
    ): TotpFactor {
        return TotpFactor::enrol(
            self::DEFAULT_ID,
            $userId,
            $scopeId,
            $sealedSecret,
            $enrolmentStartedAt ?? SystemClock::now(),
        );
    }

    public static function enabledFor(
        string $userId = UserMother::DEFAULT_ID,
        int $acceptedStep = 59_000_000,
        string $at = '2026-11-28T12:00:00+00:00',
    ): TotpFactor {
        $factor = self::pendingFor($userId);
        $factor->enable($acceptedStep, new DateTimeImmutable($at));

        return $factor;
    }
}
