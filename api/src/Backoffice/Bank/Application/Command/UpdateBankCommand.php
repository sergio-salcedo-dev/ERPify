<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Command;

/**
 * Delivery-neutral input for {@see \Erpify\Backoffice\Bank\Application\BankUpdater}.
 *
 * HTTP adapters build it via UpdateBankRequest::toCommand(); a future console
 * command (or CQRS command-bus message) constructs it directly. Carries no
 * framework/HTTP concern — invariants are enforced on the Bank entity.
 */
final readonly class UpdateBankCommand
{
    public function __construct(
        public string $name,
        public string $shortName,
    ) {
    }
}
