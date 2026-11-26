<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input payload for {@see \Erpify\Iam\Identity\Infrastructure\Controller\UserEraseController}.
 *
 * `currentPassword` is the CALLER's own password, re-proved before the erasure begins. It carries only
 * {@see ExistingCredential}'s coarse ceiling: a credential being verified is never held to today's policy.
 */
final readonly class EraseIdentityRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: ExistingCredential::LENGTH_CEILING)]
        #[SensitiveParameter]
        public string $currentPassword = '',
    ) {
    }
}
