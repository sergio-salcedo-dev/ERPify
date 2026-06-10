<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset;

use Erpify\Shared\Domain\Search\Exception\InvalidCursor;
use Erpify\Shared\Domain\Search\Exception\InvalidCursorCause;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\Cursor;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\CursorCodec;
use Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\Mother\CursorMother;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(CursorCodec::class)]
#[CoversClass(Cursor::class)]
#[CoversClass(InvalidCursor::class)]
#[CoversClass(InvalidCursorCause::class)]
final class CursorCodecTest extends TestCase
{
    private const string SECRET = 'unit-test-secret-key';

    private CursorCodec $codec;

    #[Override]
    protected function setUp(): void
    {
        // No kernel: the secret is passed directly, mirroring the DI binding.
        $this->codec = new CursorCodec(self::SECRET);
    }

    #[Test]
    public function itRoundTripsACursorExactly(): void
    {
        $cursor = CursorMother::create(fingerprint: $this->canonicalFingerprint());

        $decoded = $this->codec->decode(
            $this->codec->encode($cursor),
            $this->canonicalFingerprint(),
            $cursor->direction,
        );

        $this->assertSame($cursor->version, $decoded->version);
        $this->assertSame($cursor->direction, $decoded->direction);
        $this->assertSame($cursor->values, $decoded->values);
        $this->assertSame($cursor->fingerprint, $decoded->fingerprint);
    }

    #[Test]
    public function itEmitsExactlyOneDotSeparator(): void
    {
        $encoded = $this->codec->encode(CursorMother::create());

        $this->assertSame(1, \substr_count($encoded, '.'));
    }

    /**
     * Known-answer (golden) vector: the expected token is a hardcoded LITERAL,
     * not re-derived from the production signing expression. A silent change to
     * the wire format — HMAC algo, the 32-hex/128-bit truncation, key order,
     * base64url variant, the `.` separator — flips this byte-for-byte. The other
     * tests forge tokens with {@see signedBody}, which copies production, so only
     * this vector pins the actual on-the-wire bytes.
     */
    #[Test]
    public function itEncodesToTheKnownGoldenVector(): void
    {
        $cursor = new Cursor(
            1,
            Cursor::DIRECTION_AFTER,
            ['createdAt' => '2026-06-10T12:30:45+00:00', 'id' => '0190a0e0-0000-7000-8000-000000000000'],
            '00000000000000000000000000000000',
        );

        $expected = 'eyJ2IjoxLCJkaXIiOiJhZnRlciIsInZhbHVlcyI6eyJjcmVhdGVkQXQiOiIyMDI2'
            . 'LTA2LTEwVDEyOjMwOjQ1KzAwOjAwIiwiaWQiOiIwMTkwYTBlMC0wMDAwLTcwMDAtODAwMC0w'
            . 'MDAwMDAwMDAwMDAifSwiZnAiOiIwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMCJ9'
            . '.09701f1c1040b8ed3a7779d9347cfc96';

        $this->assertSame($expected, $this->codec->encode($cursor));
    }

    #[Test]
    public function itRoundTripsASecondPrecisionDatetimeValueExactly(): void
    {
        $values = ['createdAt' => '2026-06-10T12:30:45+00:00', 'id' => CursorMother::DEFAULT_ID];
        $cursor = CursorMother::create(values: $values, fingerprint: $this->canonicalFingerprint());

        $decoded = $this->codec->decode(
            $this->codec->encode($cursor),
            $this->canonicalFingerprint(),
            $cursor->direction,
        );

        $this->assertSame($values, $decoded->values);
    }

    #[Test]
    public function itRejectsACursorWithATamperedSignature(): void
    {
        $encoded = $this->codec->encode(CursorMother::create(fingerprint: $this->canonicalFingerprint()));
        $tampered = \substr($encoded, 0, -1) . (\str_ends_with($encoded, 'a') ? 'b' : 'a');

        $this->assertDecodeFailsWith(InvalidCursorCause::Signature, $tampered, $this->canonicalFingerprint());
    }

    #[Test]
    public function itRejectsACursorWithAnUnknownVersion(): void
    {
        $body = $this->signedBody(['v' => 2, 'dir' => Cursor::DIRECTION_AFTER, 'values' => [], 'fp' => $this->canonicalFingerprint()]);

        $this->assertDecodeFailsWith(InvalidCursorCause::Version, $body, $this->canonicalFingerprint());
    }

    #[Test]
    public function itRejectsACursorWithACorruptPayload(): void
    {
        // Valid signature and version, but the 'values' key is missing.
        $body = $this->signedBody(['v' => 1, 'dir' => Cursor::DIRECTION_AFTER, 'fp' => $this->canonicalFingerprint()]);

        $this->assertDecodeFailsWith(InvalidCursorCause::Payload, $body, $this->canonicalFingerprint());
    }

    #[Test]
    public function itRejectsACursorWhoseFingerprintDoesNotMatch(): void
    {
        $encoded = $this->codec->encode(CursorMother::create(fingerprint: $this->canonicalFingerprint()));

        $this->assertDecodeFailsWith(InvalidCursorCause::Fingerprint, $encoded, 'ffffffffffffffffffffffffffffffff');
    }

    #[Test]
    public function itRejectsACursorWhoseDirectionBindingDoesNotMatch(): void
    {
        $encoded = $this->codec->encode(CursorMother::create(direction: Cursor::DIRECTION_AFTER, fingerprint: $this->canonicalFingerprint()));

        // dir mismatch is a payload-integrity failure, never a navigation decision (AR21).
        $this->assertDecodeFailsWith(
            InvalidCursorCause::Payload,
            $encoded,
            $this->canonicalFingerprint(),
            Cursor::DIRECTION_BEFORE,
        );
    }

    #[Test]
    public function itRejectsAnOverLengthCursorBeforeAnyHmacWork(): void
    {
        $overLength = \str_repeat('A', 513);

        $this->assertDecodeFailsWith(InvalidCursorCause::Payload, $overLength, $this->canonicalFingerprint());
    }

    #[Test]
    public function itReportsSignatureBeforeVersionWhenBothAreInvalid(): void
    {
        // v=2 would be a version failure, but a tampered signature is checked first.
        $body = $this->signedBody(['v' => 2, 'dir' => Cursor::DIRECTION_AFTER, 'values' => [], 'fp' => $this->canonicalFingerprint()]);
        $tampered = \substr($body, 0, -1) . (\str_ends_with($body, 'a') ? 'b' : 'a');

        $this->assertDecodeFailsWith(InvalidCursorCause::Signature, $tampered, $this->canonicalFingerprint());
    }

    #[Test]
    public function itReportsVersionBeforePayloadWhenBothAreInvalid(): void
    {
        // Missing 'values' (payload) AND v=2 (version): version wins.
        $body = $this->signedBody(['v' => 2, 'dir' => Cursor::DIRECTION_AFTER, 'fp' => $this->canonicalFingerprint()]);

        $this->assertDecodeFailsWith(InvalidCursorCause::Version, $body, $this->canonicalFingerprint());
    }

    #[Test]
    public function itReportsPayloadBeforeFingerprintWhenBothAreInvalid(): void
    {
        // Missing 'fp' (payload) AND a mismatching expected fingerprint: payload wins.
        $body = $this->signedBody(['v' => 1, 'dir' => Cursor::DIRECTION_AFTER, 'values' => []]);

        $this->assertDecodeFailsWith(InvalidCursorCause::Payload, $body, 'ffffffffffffffffffffffffffffffff');
    }

    private function assertDecodeFailsWith(
        InvalidCursorCause $expected,
        string $encoded,
        string $expectedFingerprint,
        string $expectedDir = Cursor::DIRECTION_AFTER,
    ): void {
        try {
            $this->codec->decode($encoded, $expectedFingerprint, $expectedDir);
            $this->fail('Expected InvalidCursor to be thrown.');
        } catch (InvalidCursor $invalidCursor) {
            $this->assertSame($expected, $invalidCursor->cause);
            $this->assertSame(InvalidCursor::TYPE, $invalidCursor->type());
            $this->assertSame([], $invalidCursor->context(), 'The wire context must never reveal the cause.');
        }
    }

    /**
     * Builds a `base64url(json).hmac` token for an arbitrary (possibly malformed)
     * payload, signed with the test secret — lets each cause be isolated.
     *
     * @param array<string, mixed> $payload
     */
    private function signedBody(array $payload): string
    {
        $body = \sodium_bin2base64(
            \json_encode($payload, JSON_THROW_ON_ERROR),
            SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
        );

        return $body . '.' . \substr(\hash_hmac('sha256', $body, self::SECRET), 0, 32);
    }

    private function canonicalFingerprint(): string
    {
        return CursorMother::DEFAULT_FINGERPRINT;
    }
}
