<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain\Upload;

use DateTimeImmutable;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Exception\DeclaredSizeOutOfRange;
use Erpify\Documents\Document\Domain\Upload\LegalBasis;
use Erpify\Documents\Document\Domain\Upload\RetentionReason;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Documents\Document\Domain\UploadLimits;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The upload-intent state machine, with time passed in as the database would give it.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per transition and bound of the state machine
 */
#[CoversClass(UploadIntent::class)]
#[CoversClass(UploadLimits::class)]
final class UploadIntentTest extends TestCase
{
    private const string ACTOR = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    public function testExactlyTheCapIsAcceptedAndOneByteMoreIsNot(): void
    {
        $this->assertSame(
            UploadLimits::MAX_ORIGINAL_BYTES,
            $this->declared(UploadLimits::MAX_ORIGINAL_BYTES)->declaredSize(),
        );

        $this->expectException(DeclaredSizeOutOfRange::class);
        $this->declared(UploadLimits::MAX_ORIGINAL_BYTES + 1);
    }

    public function testZeroIsRefused(): void
    {
        $this->expectException(DeclaredSizeOutOfRange::class);
        $this->declared(0);
    }

    public function testTheMarkerIsNeverAnActor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UploadIntent::declare(
            '01a1b009-bc27-7251-8baa-d44799fcf587',
            ErasedPersonMarker::UUID,
            '7f1c2a6e-4f0b-4b8a-9f3e-0b2c4d6e8f10',
            1,
            null,
            null,
            RetentionReason::TechnicalFile,
            LegalBasis::Contract,
            null,
            $this->at(0),
        );
    }

    public function testADeclaredIntentExpiresAtItsTtl(): void
    {
        $intent = $this->declared(10);

        $this->assertSame(
            UploadIntentState::Declared,
            $intent->effectiveState($this->at(UploadLimits::DECLARED_TTL_SECONDS - 1)),
        );
        $this->assertSame(
            UploadIntentState::Expired,
            $intent->effectiveState($this->at(UploadLimits::DECLARED_TTL_SECONDS)),
        );
        $this->assertTrue($intent->isReapable($this->at(UploadLimits::DECLARED_TTL_SECONDS)));
    }

    #[DataProvider('provideTheClaimFixesTheDeadlineFromTheDeclaredSizeCases')]
    public function testTheClaimFixesTheDeadlineFromTheDeclaredSize(int $size, int $seconds): void
    {
        $intent = $this->declared($size);
        $intent->claim($this->at(0));

        $this->assertSame($seconds, $intent->uploadDeadlineAt()?->getTimestamp() - $this->at(0)->getTimestamp());
        $this->assertSame(UploadIntentState::Streaming, $intent->state());
        $this->assertTrue($intent->wasClaimed());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function provideTheClaimFixesTheDeadlineFromTheDeclaredSizeCases(): iterable
    {
        yield '100 MiB at 256 KiB/s plus the margin' => [UploadLimits::MAX_ORIGINAL_BYTES, 460];
        yield '5 MiB' => [5 * 1_048_576, 80];
        yield 'one byte rounds up to a second' => [1, 61];
    }

    public function testTheLeaseIsRenewedButNeverPastTheDeadline(): void
    {
        $intent = $this->declared(1);
        $intent->claim($this->at(0));

        $this->assertSame(
            61,
            $intent->leaseExpiresAt()?->getTimestamp() - $this->at(0)->getTimestamp(),
            'capped at the deadline',
        );

        $this->assertTrue($intent->renewLease($this->at(30)));
        $this->assertSame(61, $intent->leaseExpiresAt()?->getTimestamp() - $this->at(0)->getTimestamp());

        $this->assertFalse($intent->renewLease($this->at(61)), 'a deadline that passed ends the stream');
    }

    public function testAStreamPastItsLeaseCountsAsExpiredAndCannotCommit(): void
    {
        $intent = $this->declared(UploadLimits::MAX_ORIGINAL_BYTES);
        $intent->claim($this->at(0));

        $this->assertSame(
            UploadIntentState::Streaming,
            $intent->effectiveState($this->at(UploadLimits::LEASE_SECONDS - 1)),
        );
        $this->assertSame(UploadIntentState::Expired, $intent->effectiveState($this->at(UploadLimits::LEASE_SECONDS)));

        $this->expectException(LogicException::class);
        $intent->commit('01a1b009-bc27-7251-8baa-d44799fcf587', $this->at(UploadLimits::LEASE_SECONDS));
    }

    public function testExpiringNullsTheKeyAndSetsTheLeaseToNow(): void
    {
        $intent = $this->declared(10, \hash('sha256', 'k'));
        $intent->claim($this->at(0));
        $intent->expire($this->at(5));

        $this->assertSame(UploadIntentState::Expired, $intent->state());
        $this->assertNull($intent->keyHash());
        $this->assertSame($this->at(5)->getTimestamp(), $intent->leaseExpiresAt()?->getTimestamp());
        $this->assertTrue($intent->isReapable($this->at(5)));
    }

    public function testACommittedIntentIsNeverReapedNorExpired(): void
    {
        $intent = $this->declared(10);
        $intent->claim($this->at(0));
        $intent->commit('01a1b009-bc27-7251-8baa-d44799fcf587', $this->at(1));

        $this->assertFalse($intent->isReapable($this->at(10_000_000)));
        $this->assertSame(UploadIntentState::Committed, $intent->effectiveState($this->at(10_000_000)));

        $this->expectException(LogicException::class);
        $intent->expire($this->at(2));
    }

    public function testAClaimNeedsALiveDeclaredIntent(): void
    {
        $intent = $this->declared(10);

        $this->expectException(LogicException::class);
        $intent->claim($this->at(UploadLimits::DECLARED_TTL_SECONDS));
    }

    public function testAReplayMatchesOnlyTheSameDeclaration(): void
    {
        $sha = \hash('sha256', 'x');
        $intent = $this->declared(10, \hash('sha256', 'k'), $sha);

        $this->assertTrue($intent->matchesDeclaration(
            10,
            $sha,
            RetentionReason::TechnicalFile,
            LegalBasis::Contract,
            null,
        ));
        $this->assertFalse($intent->matchesDeclaration(
            11,
            $sha,
            RetentionReason::TechnicalFile,
            LegalBasis::Contract,
            null,
        ));
        $this->assertFalse($intent->matchesDeclaration(
            10,
            $sha,
            RetentionReason::LegalClaims,
            LegalBasis::Contract,
            null,
        ));
        $this->assertFalse($intent->matchesDeclaration(
            10,
            $sha,
            RetentionReason::TechnicalFile,
            LegalBasis::Consent,
            null,
        ));
        $this->assertFalse($intent->matchesDeclaration(
            10,
            $sha,
            RetentionReason::TechnicalFile,
            LegalBasis::Contract,
            $this->at(0),
        ));
    }

    private function declared(int $size, ?string $keyHash = null, ?string $sha = null): UploadIntent
    {
        return UploadIntent::declare(
            '01a1b009-bc27-7251-8baa-d44799fcf587',
            self::ACTOR,
            '7f1c2a6e-4f0b-4b8a-9f3e-0b2c4d6e8f10',
            $size,
            $sha ?? (null === $keyHash ? null : \hash('sha256', 'x')),
            $keyHash,
            RetentionReason::TechnicalFile,
            LegalBasis::Contract,
            null,
            $this->at(0),
        );
    }

    private function at(int $seconds): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . (2_000_000_000 + $seconds));
    }
}
