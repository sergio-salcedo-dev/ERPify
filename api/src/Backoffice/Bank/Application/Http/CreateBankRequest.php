<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Http;

use Erpify\Backoffice\Bank\Application\Command\CreateBankCommand;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateBankRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'The name field is required.')]
        #[Assert\Length(max: 255, maxMessage: 'The name must not exceed {{ limit }} characters.')]
        public string $name = '',
        #[Assert\NotBlank(message: 'The shortName field is required.')]
        #[Assert\Length(max: 50, maxMessage: 'The shortName must not exceed {{ limit }} characters.')]
        public string $shortName = '',
    ) {
    }

    public function toCommand(): CreateBankCommand
    {
        return new CreateBankCommand($this->name, $this->shortName);
    }
}
