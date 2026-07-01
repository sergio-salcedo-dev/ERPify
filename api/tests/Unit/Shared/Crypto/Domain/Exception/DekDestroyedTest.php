<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Domain\Exception;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DekDestroyed::class)]
final class DekDestroyedTest extends TestCase
{
    #[Test]
    public function itCarriesTheScopeAsContext(): void
    {
        $scope = EncryptionScopeId::forBankAccount(Uuid::generate());

        $exception = DekDestroyed::forScope($scope);

        $this->assertSame(DekDestroyed::TYPE, $exception->type());
        $this->assertSame('dek-destroyed', $exception->type());
        $this->assertSame('The data-encryption key for this scope is unavailable.', $exception->title());
        $this->assertSame(['encryptionScopeId' => $scope->toString()], $exception->context());
    }
}
