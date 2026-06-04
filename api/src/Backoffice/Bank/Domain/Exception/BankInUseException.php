<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Exception;

use Erpify\Shared\Domain\Exception\Conflict;
use Erpify\Shared\Domain\Exception\DomainException;

final class BankInUseException extends DomainException implements Conflict
{
    public static function withAccountCount(string $id, int $accountCount): self
    {
        $accountNoun = 1 === $accountCount ? 'account' : 'accounts';

        return new self(
            type: 'bank-in-use',
            title: \sprintf(
                'Cannot delete the bank because it still has %d associated %s.',
                $accountCount,
                $accountNoun,
            ),
            context: ['bankId' => $id, 'accountCount' => $accountCount],
        );
    }
}
