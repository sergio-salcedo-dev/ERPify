<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Exception;

use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountNotFoundException::class)]
final class BankAccountNotFoundExceptionTest extends TestCase
{
    private const string ACCOUNT_ID = '33333333-3333-7000-8000-000000000001';

    public function testWithIdNamesTheTypeAndCarriesTheIdInMessageAndContext(): void
    {
        $exception = BankAccountNotFoundException::withId(self::ACCOUNT_ID);

        $this->assertSame('bank-account-not-found', $exception->type());
        $this->assertSame(
            \sprintf('Bank account with id <%s> not found.', self::ACCOUNT_ID),
            $exception->getMessage(),
        );
        $this->assertSame(['bankAccountId' => self::ACCOUNT_ID], $exception->context());
    }

    /**
     * Unlike {@see testWithIdNamesTheTypeAndCarriesTheIdInMessageAndContext()}: the IBAN is classified
     * PII, so — unlike the id — it must never appear in the message or the context.
     */
    public function testWithIbanNamesTheTypeAndCarriesNoIbanAnywhere(): void
    {
        $exception = BankAccountNotFoundException::withIban();

        $this->assertSame('bank-account-not-found', $exception->type());
        $this->assertSame('Bank account with the given IBAN not found.', $exception->getMessage());
        $this->assertSame([], $exception->context());
    }
}
