<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Request;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final class BankInput
{
    #[Assert\NotBlank(message: 'The name field is required.')]
    #[Assert\Length(max: 255, maxMessage: 'The name must not exceed {{ limit }} characters.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'The short_name field is required.')]
    #[Assert\Length(max: 50, maxMessage: 'The short_name must not exceed {{ limit }} characters.')]
    #[SerializedName('short_name')]
    public string $shortName = '';
}
