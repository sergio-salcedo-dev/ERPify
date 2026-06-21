<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Event\Mother;

use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;

final class BankSnapshotMother
{
    public static function create(
        string $name = 'Acme Savings',
        string $shortName = 'ACME',
        string $createdAt = '2026-06-06T00:00:00+00:00',
        string $updatedAt = '2026-06-06T00:00:00+00:00',
        ?string $logoMediaId = null,
        ?string $logoContentHash = null,
        ?string $storedObjectContentHash = null,
        ?string $storedObjectMimeType = null,
    ): BankSnapshot {
        return new BankSnapshot(
            $name,
            $shortName,
            $createdAt,
            $updatedAt,
            $logoMediaId,
            $logoContentHash,
            $storedObjectContentHash,
            $storedObjectMimeType,
        );
    }
}
