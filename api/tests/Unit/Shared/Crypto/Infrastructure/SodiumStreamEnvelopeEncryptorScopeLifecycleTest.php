<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Infrastructure;

use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumStreamEnvelopeEncryptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sealing and opening under a scope whose key was destroyed, whether after use or before it was ever minted.
 *
 * @internal
 */
#[CoversClass(SodiumStreamEnvelopeEncryptor::class)]
#[CoversClass(ScopeKeyring::class)]
final class SodiumStreamEnvelopeEncryptorScopeLifecycleTest extends TestCase
{
    use StreamSealingFixture;

    #[Test]
    public function itRefusesToSealUnderADestroyedScope(): void
    {
        $scope = $this->documentScope();
        $this->encryptor->seal($scope, $this->memory('first'), $this->memory(), self::AAD);

        $this->keystore->destroy($scope, DestroyReason::Erasure);

        $this->expectException(DekDestroyed::class);

        $this->encryptor->seal($scope, $this->memory('second'), $this->memory(), self::AAD);
    }

    #[Test]
    public function itRefusesToSealUnderAScopeDestroyedBeforeItWasMinted(): void
    {
        $scope = $this->documentScope();
        $this->keystore->destroy($scope, DestroyReason::AbortedUpload);

        $this->expectException(DekDestroyed::class);

        $this->encryptor->seal($scope, $this->memory('late writer'), $this->memory(), self::AAD);
    }

    #[Test]
    public function aSealedObjectCannotBeOpenedOnceItsScopeIsDestroyed(): void
    {
        $scope = $this->documentScope();
        $sealed = $this->sealedBytes($scope, 'evidence');

        $this->keystore->destroy($scope, DestroyReason::Erasure);

        $this->expectException(DekDestroyed::class);

        $this->encryptor->unseal($scope, $this->memory($sealed), $this->memory(), self::AAD);
    }
}
