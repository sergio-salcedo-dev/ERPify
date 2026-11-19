<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Shared\Images\Application\ImageAttachment;

/**
 * Removes a bank's logo and queues its release. Idempotent: a bank that holds none is left as it is.
 */
final readonly class BankLogoRemover
{
    public function __construct(
        private ImageAttachment $imageAttachment,
        private BankLogoHolder $bankLogoHolder,
    ) {
    }

    /**
     * @throws BankNotFoundException
     */
    public function remove(string $bankId): void
    {
        $this->imageAttachment->remove($this->bankLogoHolder, $bankId);
    }
}
