<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

/**
 * What a consumer's `ImageHolder` raises for an owner that is gone: its own `NotFound`, which its route answers 404.
 *
 * @internal
 */
final class OwnerNotFound extends DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct(type: 'owner-not-found', title: 'The owner does not exist.');
    }
}
