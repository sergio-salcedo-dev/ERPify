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

    public function testEnrichAllPairsEachBankWithItsCountAndZeroWhenAbsentInOneBatchedCall(): void
    {
        $counter = new InMemoryBankAccountCounter([self::BANK_A => 12, self::BANK_B => 3]);
        $banks = [
            BankMother::create(self::BANK_A, 'Bank A', 'BA'),
            BankMother::create(self::BANK_B, 'Bank B', 'BB'),
            BankMother::create(self::BANK_C, 'Bank C', 'BC'),
        ];

        $enriched = (new BankAccountCountEnricher($counter))->enrichAll($banks);

        $this->assertCount(3, $enriched);
        $this->assertSame(12, $enriched[0]->accountCount);
        $this->assertSame(3, $enriched[1]->accountCount);
        // BANK_C is absent from the count map -> no accounts -> 0.
        $this->assertSame(0, $enriched[2]->accountCount);
        // The aggregates ride along unmutated, in the order they were passed.
        $this->assertSame($banks[0], $enriched[0]->bank);
        $this->assertSame($banks[1], $enriched[1]->bank);
        $this->assertSame($banks[2], $enriched[2]->bank);
        $this->assertSame(
            [[self::BANK_A, self::BANK_B, self::BANK_C]],
            $counter->receivedBankIds,
            'Every id is resolved in a single batched call, never one call per bank.',
        );
    }

    public function testEnrichAllOnAnEmptyListStillResolvesAnEmptyBatchWithoutFailing(): void
    {
        $counter = new InMemoryBankAccountCounter();

        $enriched = (new BankAccountCountEnricher($counter))->enrichAll([]);

        $this->assertSame([], $enriched);
        $this->assertSame([[]], $counter->receivedBankIds);
    }

    public function testEnrichPairsTheSingleBankWithItsCount(): void
    {
        $counter = new InMemoryBankAccountCounter([self::BANK_A => 7]);
        $bank = BankMother::create(self::BANK_A, 'Bank A', 'BA');

        $enriched = (new BankAccountCountEnricher($counter))->enrich($bank);

        $this->assertSame(7, $enriched->accountCount);
        $this->assertSame($bank, $enriched->bank);
    }

    public function testCountForReturnsTheCountForAPresentIdAndZeroForAnAbsentOne(): void
    {
        $enricher = new BankAccountCountEnricher(new InMemoryBankAccountCounter([self::BANK_A => 5]));

        $this->assertSame(5, $enricher->countFor(self::BANK_A));
        $this->assertSame(0, $enricher->countFor(self::BANK_B));
    }
}
