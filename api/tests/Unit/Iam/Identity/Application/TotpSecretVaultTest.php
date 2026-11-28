<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\TotpSecretVault;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorIntegrityFailure;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Domain\KeyState;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\TotpFactorMother;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Crypto\InMemoryKeystore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The vault's scope is canonical: keystore scopes compare exactly, so a scope id spelled in upper case must seal
 * under the same keystore row that the lower-cased row then opens and the erasure destroys.
 *
 * @internal
 */
#[CoversClass(TotpSecretVault::class)]
final class TotpSecretVaultTest extends TestCase
{
    private const string KEK = '0123456789abcdef0123456789abcdef';

    private const string SECRET = 'twenty-bytes-secret!';

    #[Test]
    public function anUpperCaseScopeSealsUnderTheKeyTheLowerCaseRowOpensAndTheErasureDestroys(): void
    {
        $keystore = new InMemoryKeystore();
        $vault = new TotpSecretVault(new SodiumEnvelopeEncryptor(new ScopeKeyring($keystore, self::KEK)));
        $upper = \strtoupper(TotpFactorMother::DEFAULT_SCOPE_ID);

        $sealed = $vault->seal($upper, \strtoupper(UserMother::DEFAULT_ID), self::SECRET);
        $factor = TotpFactorMother::pendingFor(scopeId: $upper, sealedSecret: $sealed);

        $this->assertSame(
            TotpSecretVault::scopeOf(TotpFactorMother::DEFAULT_SCOPE_ID)->toString(),
            TotpSecretVault::scopeOf($upper)->toString(),
        );
        $this->assertSame(
            KeyState::Live,
            $keystore->lockState(TotpSecretVault::scopeOf(TotpFactorMother::DEFAULT_SCOPE_ID)),
            'the key was minted under the lower-cased scope',
        );
        $this->assertSame(self::SECRET, $vault->open($factor));

        $keystore->destroy(TotpSecretVault::scopeOf($factor->scopeId()), DestroyReason::Erasure);

        try {
            $vault->open($factor);
            $this->fail('The destroyed key must be the one the secret was sealed under.');
        } catch (SecondFactorIntegrityFailure $secondFactorIntegrityFailure) {
            $this->assertInstanceOf(DekDestroyed::class, $secondFactorIntegrityFailure->getPrevious());
        }
    }
}
