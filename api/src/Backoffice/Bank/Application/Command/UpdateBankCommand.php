<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input command for {@see \Erpify\Backoffice\Bank\Application\BankUpdater}.
 *
 * HTTP maps it from the request body via #[MapRequestPayload]; a console
 * command (or CQRS command bus) builds it directly with `new`. The #[Assert]
 * attributes are passive validation metadata, enforced at the HTTP boundary
 * and re-checkable via Validator::ensure() for non-HTTP callers; Bank entity
 * invariants are the final guard.
 */
final readonly class UpdateBankCommand
{
    public function __construct(
        #[Assert\NotBlank(message: 'The name field is required.')]
        #[Assert\Length(max: 255, maxMessage: 'The name must not exceed {{ limit }} characters.')]
        public string $name = '',
        #[Assert\NotBlank(message: 'The code field is required.')]
        #[Assert\Length(max: 50, maxMessage: 'The code must not exceed {{ limit }} characters.')]
        public string $shortName = '',
    ) {
    }
}
