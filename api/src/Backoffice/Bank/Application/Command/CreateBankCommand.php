<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input command for {@see \Erpify\Backoffice\Bank\Application\BankCreator}.
 *
 * Every caller builds it with `new`: over HTTP the controller translates
 * {@see \Erpify\Backoffice\Bank\Infrastructure\Http\CreateBankRequest} into it, and a console command (or CQRS
 * command bus) constructs it directly. It carries no transport vocabulary, so uploads reach the use case as
 * separate arguments rather than on this command. The #[Assert] attributes are passive validation metadata,
 * enforced at the HTTP boundary and re-checkable via Validator::ensure() for non-HTTP callers; Bank entity
 * invariants are the final guard.
 */
final readonly class CreateBankCommand
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
