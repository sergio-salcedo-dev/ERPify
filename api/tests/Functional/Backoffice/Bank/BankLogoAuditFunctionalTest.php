<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank;

use Erpify\Backoffice\Bank\Application\BankLogoHolder;
use Erpify\Tests\Functional\Backoffice\Bank\Fixtures\BankLogoRig;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the trail holds for a logo write, read back from the rows the audit listener really wrote, over the real ORM
 * holder and a committed bank.
 *
 * @internal
 */
#[CoversClass(BankLogoHolder::class)]
final class BankLogoAuditFunctionalTest extends KernelTestCase
{
    use BankLogoRig;
    use ResolvesContainerServices;

    #[Override]
    protected function setUp(): void
    {
        $this->bootRig();
    }

    protected function tearDown(): void
    {
        $this->tearDownRig();

        parent::tearDown();
    }

    /**
     * Set, replace and remove each leave exactly one `BANK_UPDATED` change row. Its diff is read back as the audit
     * listener really wrote it — `logoImageId` old and new, plus the timestamp — and carries no bytes, no filename and
     * no other field. The trail therefore holds the id and nothing a person could be recognised by.
     */
    #[Test]
    public function eachLogoWriteLeavesOneUpdatedAuditRowWhoseDiffHoldsOnlyTheIdAndTheTimestamp(): void
    {
        $bankId = $this->seedBank(null);
        $attachment = $this->attachment();
        $holder = $this->holder();

        $first = $attachment->replace($holder, $bankId, $this->png(), 'image/png')->toString();
        $this->assertSame(1, $this->updatedRows($bankId));

        $second = $attachment->replace($holder, $bankId, $this->png())->toString();
        $this->assertSame(2, $this->updatedRows($bankId));

        $attachment->remove($holder, $bankId);
        $this->assertSame(3, $this->updatedRows($bankId));

        $diffs = $this->logoDiffs($bankId);
        $this->assertContains(['old' => null, 'new' => $first], $diffs, 'null to id');
        $this->assertContains(['old' => $first, 'new' => $second], $diffs, 'id to id');
        $this->assertContains(['old' => $second, 'new' => null], $diffs, 'id to null');
        $this->assertCount(3, $diffs);

        foreach ($this->auditMetadata($bankId) as $metadata) {
            $changes = $metadata['changes'] ?? null;
            $this->assertIsArray($changes);
            $this->assertSame(['logoImageId'], \array_values(\array_diff(\array_keys($changes), ['updatedAt'])));
            $serialized = \json_encode($metadata, JSON_THROW_ON_ERROR);
            $this->assertDoesNotMatchRegularExpression('/png|jpe?g|image\/|filename/i', $serialized);
        }

        $this->assertSame(1, $this->queuedFor($first), 'replaced, so released once');
        $this->assertSame(1, $this->queuedFor($second), 'removed, so released once');
    }

    private function updatedRows(string $bankId): int
    {
        $count = $this->outside->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE resource_id = ? AND action = 'BANK_UPDATED' AND level = 'change'",
            [$bankId],
        );

        return \is_numeric($count) ? (int) $count : -1;
    }

    /**
     * @return list<array<string, mixed>> the decoded `metadata` of every `BANK_UPDATED` row of the bank
     */
    private function auditMetadata(string $bankId): array
    {
        $decoded = [];

        foreach (
            $this->outside->fetchFirstColumn(
                "SELECT metadata FROM audit_log WHERE resource_id = ? AND action = 'BANK_UPDATED' AND level = 'change'",
                [$bankId],
            ) as $metadata
        ) {
            $row = \json_decode(\is_string($metadata) ? $metadata : '', true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($row);
            /** @var array<string, mixed> $row */
            $decoded[] = $row;
        }

        return $decoded;
    }

    /**
     * @return list<array{old: mixed, new: mixed}> the `logoImageId` change of each `BANK_UPDATED` row
     */
    private function logoDiffs(string $bankId): array
    {
        $diffs = [];

        foreach ($this->auditMetadata($bankId) as $metadata) {
            $changes = $metadata['changes'] ?? null;
            $this->assertIsArray($changes);
            $change = $changes['logoImageId'] ?? null;
            $this->assertIsArray($change);
            $diffs[] = ['old' => $change['old'] ?? null, 'new' => $change['new'] ?? null];
        }

        return $diffs;
    }
}
