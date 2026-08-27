<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountIbanLookupRepository;
use Override;

/**
 * In-memory {@see BankAccountIbanLookupRepository} returning a prebuilt row (or null) and recording the
 * IBAN it was asked to look up, so a test can assert the canonical value reaches the repository port.
 *
 * @internal
 */
final class InMemoryBankAccountIbanLookupRepository implements BankAccountIbanLookupRepository
{
    public ?string $askedFor = null;

    public function __construct(private readonly ?BankAccountCollectionRow $row)
    {
    }

    #[Override]
    public function findByIban(string $canonicalIban): ?BankAccountCollectionRow
    {
        $this->askedFor = $canonicalIban;

        return $this->row;
    }
}
