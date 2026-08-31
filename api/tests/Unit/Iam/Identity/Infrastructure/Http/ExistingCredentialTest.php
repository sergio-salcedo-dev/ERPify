<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Infrastructure\Http\ChangeMyPasswordRequest;
use Erpify\Iam\Identity\Infrastructure\Http\ExistingCredential;
use Erpify\Iam\Identity\Infrastructure\Http\MintRecoverySecretRequest;
use Erpify\Iam\Identity\Infrastructure\Http\RevokeRecoverySecretRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The one bound on a credential being RE-PROVED, and the two properties that keep it one.
 *
 * The number is a transport bound and not a password rule: no policy may apply to a value minted under
 * whatever rule stood when its owner set it, because asserting today's minimum on it would lock out exactly
 * the holder of a legacy password — and lock them out of the endpoint that replaces it and of the recovery
 * credential that is their way back in.
 *
 * What this pins is that the three writes under `/me` read the SAME bound. A per-DTO copy is a number three
 * files drift apart on, silently and in the direction that widens.
 *
 * @internal
 */
#[CoversClass(ExistingCredential::class)]
final class ExistingCredentialTest extends TestCase
{
    /**
     * @param class-string $request
     */
    #[Test]
    #[DataProvider('provideEveryReProofReadsTheOneSharedCeilingCases')]
    public function everyReProofReadsTheOneSharedCeiling(string $request, string $property): void
    {
        $lengths = [];

        foreach ((new ReflectionClass($request))->getProperty($property)->getAttributes(Length::class) as $attribute) {
            $lengths[] = $attribute->newInstance()->max;
        }

        $this->assertSame(
            [ExistingCredential::LENGTH_CEILING],
            $lengths,
            'this request bounds its credential by a number of its own',
        );
    }

    /**
     * Every request DTO that carries a `currentPassword`. A fourth arriving with its own literal is what this
     * list exists to make visible.
     *
     * @return iterable<string, array{class-string, string}>
     */
    public static function provideEveryReProofReadsTheOneSharedCeilingCases(): iterable
    {
        yield 'change password' => [ChangeMyPasswordRequest::class, 'currentPassword'];
        yield 'mint a recovery secret' => [MintRecoverySecretRequest::class, 'currentPassword'];
        yield 'revoke a recovery secret' => [RevokeRecoverySecretRequest::class, 'currentPassword'];
    }

    #[Test]
    public function theCeilingIsFarAboveAnyCredentialTheSystemCanHold(): void
    {
        // Coarse on purpose: its job is to stop an oversized body turning one KDF run into an amplification
        // lever, never to say anything about how long a password should be. A value near a plausible password
        // length would be the second thing, and would refuse real credentials.
        // Read through reflection rather than named directly: PHPStan folds a literal compared against a
        // constant into a tautology and says so, which would leave the pair with no guard at all.
        $ceiling = (new ReflectionClass(ExistingCredential::class))->getConstant('LENGTH_CEILING');

        $this->assertSame(255, $ceiling);
    }

    #[Test]
    public function itIsANamespaceForThatBoundAndNeverAValue(): void
    {
        $this->assertFalse(
            (new ReflectionClass(ExistingCredential::class))->isInstantiable(),
            'a bound that can be constructed invites being passed around as one',
        );
    }
}
