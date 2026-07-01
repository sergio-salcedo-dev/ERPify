<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Domain;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\InvalidEncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EncryptionScopeId::class)]
final class EncryptionScopeIdTest extends TestCase
{
    #[Test]
    public function itBuildsABankAccountScope(): void
    {
        $id = Uuid::generate();

        $this->assertSame('BankAccount:' . $id, EncryptionScopeId::forBankAccount($id)->toString());
    }

    #[Test]
    public function itRoundTripsThroughItsStringForm(): void
    {
        $id = Uuid::generate();

        $scope = EncryptionScopeId::fromString('BankAccount:' . $id);

        $this->assertSame('BankAccount', $scope->type);
        $this->assertSame($id, $scope->id);
    }

    #[Test]
    public function itRejectsAValueThatIsNotATypeUuidPair(): void
    {
        $this->expectException(InvalidEncryptionScopeId::class);

        EncryptionScopeId::fromString('not-a-scope');
    }

    #[Test]
    public function itRejectsANonUuidId(): void
    {
        $this->expectException(InvalidEncryptionScopeId::class);

        EncryptionScopeId::forBankAccount('not-a-uuid');
    }
}
