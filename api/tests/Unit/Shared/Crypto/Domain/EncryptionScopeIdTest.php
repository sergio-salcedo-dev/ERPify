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
    public function itRejectsAValueThatIsNotATypeIdPair(): void
    {
        $this->expectException(InvalidEncryptionScopeId::class);

        EncryptionScopeId::fromString('not-a-scope');
    }

    #[Test]
    public function itAcceptsANonUuidIdWithoutSeparator(): void
    {
        $scope = EncryptionScopeId::of('Party', 'slug-42');

        $this->assertSame('Party:slug-42', $scope->toString());
    }

    #[Test]
    public function itRoundTripsANonUuidId(): void
    {
        $scope = EncryptionScopeId::fromString('Party:slug-42');

        $this->assertSame('Party', $scope->type);
        $this->assertSame('slug-42', $scope->id);
    }

    #[Test]
    public function itRejectsATypeContainingTheSeparator(): void
    {
        $this->expectException(InvalidEncryptionScopeId::class);

        EncryptionScopeId::of('Ba:d', Uuid::generate());
    }

    #[Test]
    public function itRejectsAnIdContainingTheSeparator(): void
    {
        $this->expectException(InvalidEncryptionScopeId::class);

        EncryptionScopeId::of('BankAccount', 'a:b');
    }

    #[Test]
    public function itRejectsAnEmptyId(): void
    {
        $this->expectException(InvalidEncryptionScopeId::class);

        EncryptionScopeId::of('BankAccount', '');
    }
}
