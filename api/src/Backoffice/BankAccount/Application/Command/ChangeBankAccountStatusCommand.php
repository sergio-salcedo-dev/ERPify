<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Application\Command;

use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;

/**
 * Input command for {@see \Erpify\Backoffice\BankAccount\Application\BankAccountStatusChanger}.
 *
 * `status` is a required, typed transition target: `#[MapRequestPayload]` rejects a missing or
 * out-of-enum value as a 422 at the HTTP boundary. Status lives in its own command (and endpoint),
 * never in the descriptive update payload — a lifecycle transition is a distinct intent.
 */
final readonly class ChangeBankAccountStatusCommand
{
    public function __construct(
        public BankAccountStatus $status,
    ) {
    }
}
