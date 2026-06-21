<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankAccountCountEnricher;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountCountEnricher::class)]
final class BankAccountCountEnricherTest extends TestCase
{
    private const string BANK_A = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b01';

    private const string BANK_B = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b02';

    private const string BANK_C = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b03';

    public function testEnrichAllAssignsEachCountAndZeroWhenAbsentInOneBatchedCall(): void
    {
        $counter = new InMemoryBankAccountCounter([self::BANK_A => 12, self::BANK_B => 3]);
        $banks = [
            BankMother::create(self::BANK_A, 'Bank A', 'BA'),
            BankMother::create(self::BANK_B, 'Bank B', 'BB'),
            BankMother::create(self::BANK_C, 'Bank C', 'BC'),
        ];

        (new BankAccountCountEnricher($counter))->enrichAll($banks);

        $this->assertSame(12, $banks[0]->getAccountCount());
        $this->assertSame(3, $banks[1]->getAccountCount());
        // BANK_C is absent from the count map -> no accounts -> 0.
        $this->assertSame(0, $banks[2]->getAccountCount());
        $this->assertSame(
            [[self::BANK_A, self::BANK_B, self::BANK_C]],
            $counter->receivedBankIds,
            'Every id is resolved in a single batched call, never one call per bank.',
        );
    }

    public function testEnrichAllOnAnEmptyListStillResolvesAnEmptyBatchWithoutFailing(): void
    {
        $counter = new InMemoryBankAccountCounter();

        (new BankAccountCountEnricher($counter))->enrichAll([]);

        $this->assertSame([[]], $counter->receivedBankIds);
    }

    public function testEnrichAssignsTheSingleBankItsCount(): void
    {
        $counter = new InMemoryBankAccountCounter([self::BANK_A => 7]);
        $bank = BankMother::create(self::BANK_A, 'Bank A', 'BA');

        (new BankAccountCountEnricher($counter))->enrich($bank);

        $this->assertSame(7, $bank->getAccountCount());
    }

    public function testCountForReturnsTheCountForAPresentIdAndZeroForAnAbsentOne(): void
    {
        $enricher = new BankAccountCountEnricher(new InMemoryBankAccountCounter([self::BANK_A => 5]));

        $this->assertSame(5, $enricher->countFor(self::BANK_A));
        $this->assertSame(0, $enricher->countFor(self::BANK_B));
    }
}
