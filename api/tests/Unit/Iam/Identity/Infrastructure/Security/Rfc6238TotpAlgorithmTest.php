<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use DateTimeImmutable;
use Erpify\Iam\Identity\Infrastructure\Security\Rfc6238TotpAlgorithm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The in-house RFC 6238 adapter against the RFC's own appendix B vectors (the SHA1 column, secret
 * `12345678901234567890`), and the window and replay rules the second factor's security argument rests on.
 *
 * The RFC prints eight-digit codes. Six digits are the same truncation taken modulo 10^6, so each expected value
 * here is the last six digits of the RFC's — a faithful check of the six-digit adapter, not a weaker one.
 *
 * @internal
 */
#[CoversClass(Rfc6238TotpAlgorithm::class)]
final class Rfc6238TotpAlgorithmTest extends TestCase
{
    private const string RFC_SECRET = '12345678901234567890';

    #[Test]
    #[DataProvider('provideItComputesTheRfc6238VectorsCases')]
    public function itComputesTheRfc6238Vectors(int $unixTime, string $expected): void
    {
        $algorithm = new Rfc6238TotpAlgorithm();

        $this->assertSame($expected, $algorithm->codeAt(self::RFC_SECRET, \intdiv($unixTime, 30)));
        $this->assertSame(
            \intdiv($unixTime, 30),
            $algorithm->matchingStep(self::RFC_SECRET, $expected, new DateTimeImmutable('@' . $unixTime), null),
        );
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideItComputesTheRfc6238VectorsCases(): iterable
    {
        yield 'T = 59' => [59, '287082'];
        yield 'T = 1111111109' => [1_111_111_109, '081804'];
        yield 'T = 1111111111' => [1_111_111_111, '050471'];
        yield 'T = 1234567890' => [1_234_567_890, '005924'];
        yield 'T = 2000000000' => [2_000_000_000, '279037'];
        yield 'T = 20000000000' => [20_000_000_000, '353130'];
    }

    #[Test]
    public function aCodeOneStepEitherSideIsAcceptedAndTwoStepsAwayIsNot(): void
    {
        $step = \intdiv(1_234_567_890, 30);

        $this->assertSame($step - 1, $this->stepMatchedByTheCodeOf($step - 1, $step));
        $this->assertSame($step + 1, $this->stepMatchedByTheCodeOf($step + 1, $step));
        $this->assertNull($this->stepMatchedByTheCodeOf($step - 2, $step));
        $this->assertNull($this->stepMatchedByTheCodeOf($step + 2, $step));
    }

    #[Test]
    public function aCodeAtOrBeforeTheLastAcceptedStepIsRefused(): void
    {
        $algorithm = new Rfc6238TotpAlgorithm();
        $at = new DateTimeImmutable('@59');
        $step = \intdiv(59, 30);

        $this->assertSame($step, $algorithm->matchingStep(self::RFC_SECRET, '287082', $at, $step - 1));
        $this->assertNull($algorithm->matchingStep(self::RFC_SECRET, '287082', $at, $step), 'a replay is refused');
        $this->assertNull($algorithm->matchingStep(self::RFC_SECRET, '287082', $at, $step + 1));
    }

    #[Test]
    public function whenTwoStepsOfTheWindowShareACodeTheLatestIsAccepted(): void
    {
        // Steps 153567 and 153569 of the RFC secret both yield 468457 (found by search), so at step 153568 the
        // window holds the code twice. Accepting the later one moves the stored step as far forward as the code
        // allows, so the earlier step cannot be replayed afterwards.
        $algorithm = new Rfc6238TotpAlgorithm();
        $this->assertSame('468457', $algorithm->codeAt(self::RFC_SECRET, 153_567));
        $this->assertSame('468457', $algorithm->codeAt(self::RFC_SECRET, 153_569));

        $at = new DateTimeImmutable('@' . (153_568 * 30));

        $this->assertSame(153_569, $algorithm->matchingStep(self::RFC_SECRET, '468457', $at, null));
        $this->assertNull($algorithm->matchingStep(self::RFC_SECRET, '468457', $at, 153_569));
    }

    #[Test]
    #[DataProvider('provideAMalformedCodeIsRefusedCases')]
    public function aMalformedCodeIsRefused(string $code): void
    {
        $this->assertNull(
            (new Rfc6238TotpAlgorithm())->matchingStep(self::RFC_SECRET, $code, new DateTimeImmutable('@59'), null),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAMalformedCodeIsRefusedCases(): iterable
    {
        yield 'empty' => [''];
        yield 'five digits' => ['28708'];
        yield 'seven digits' => ['2870820'];
        yield 'the right digits with a trailing newline' => ["287082\n"];
        yield 'letters' => ['abcdef'];
        yield 'the right digits padded with a space' => [' 287082'];
    }

    #[Test]
    public function theSecretIsEncodedInUnpaddedRfc4648Base32(): void
    {
        $algorithm = new Rfc6238TotpAlgorithm();

        $this->assertSame('MZXW6YTBOI', $algorithm->encodedSecret('foobar'));
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $algorithm->encodedSecret(self::RFC_SECRET));
    }

    #[Test]
    public function aNewSecretIsTwentyFreshBytes(): void
    {
        $algorithm = new Rfc6238TotpAlgorithm();

        $this->assertSame(Rfc6238TotpAlgorithm::SECRET_BYTES, \strlen($algorithm->newSecret()));
        $this->assertNotSame($algorithm->newSecret(), $algorithm->newSecret());
    }

    #[Test]
    public function theProvisioningUriCarriesTheSecretAndTheFixedParameters(): void
    {
        $uri = (new Rfc6238TotpAlgorithm())->provisioningUri(self::RFC_SECRET, 'alice@erpify.test');

        $this->assertSame(
            'otpauth://totp/ERPify%3Aalice%40erpify.test?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
            . '&issuer=ERPify&algorithm=SHA1&digits=6&period=30',
            $uri,
        );
    }

    private function stepMatchedByTheCodeOf(int $codeStep, int $currentStep): ?int
    {
        $algorithm = new Rfc6238TotpAlgorithm();

        return $algorithm->matchingStep(
            self::RFC_SECRET,
            $algorithm->codeAt(self::RFC_SECRET, $codeStep),
            new DateTimeImmutable('@' . ($currentStep * 30)),
            null,
        );
    }
}
