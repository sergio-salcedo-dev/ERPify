<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Throwable;

final class BankInUseException extends DomainException implements Conflict
{
    public static function withAccountCount(string $id, int $accountCount, ?Throwable $previous = null): self
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
            previous: $previous,
        );
    }
}
