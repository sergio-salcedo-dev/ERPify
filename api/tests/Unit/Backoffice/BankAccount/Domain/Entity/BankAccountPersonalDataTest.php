<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Shared\Privacy\Infrastructure\ReflectionPersonalDataClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccount::class)]
final class BankAccountPersonalDataTest extends TestCase
{
    #[Test]
    public function holderNameAndIbanAreItsOnlyPersonalDataFields(): void
    {
        $classifier = new ReflectionPersonalDataClassifier();

        $this->assertSame(['holderName', 'iban'], $classifier->personalFieldsOf(BankAccount::class));
    }
}
