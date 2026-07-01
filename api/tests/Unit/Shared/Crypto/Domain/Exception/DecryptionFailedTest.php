<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Domain\Exception;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DecryptionFailed::class)]
final class DecryptionFailedTest extends TestCase
{
    #[Test]
    public function itCarriesTheScopeAsContextWithoutTheCiphertext(): void
    {
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());

        $exception = DecryptionFailed::forScope($scope);

        $this->assertSame(DecryptionFailed::TYPE, $exception->type());
        $this->assertSame('decryption-failed', $exception->type());
        $this->assertSame('Authenticated decryption failed.', $exception->title());
        $this->assertSame(['encryptionScopeId' => $scope->toString()], $exception->context());
    }
}
