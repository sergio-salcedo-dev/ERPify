<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence;

use Erpify\Backoffice\BankAccount\Application\Evidence\ReleaseBankAccountEvidence;

/**
 * The evidence release of an account that holds none, for a test about the account's removal rather than its
 * evidence.
 *
 * @internal
 */
final class InertEvidenceRelease
{
    private function __construct()
    {
    }

    public static function create(): ReleaseBankAccountEvidence
    {
        return new ReleaseBankAccountEvidence(
            new InMemoryBankAccountEvidenceRepository(),
            new FakeEvidenceDocuments(),
        );
    }
}
