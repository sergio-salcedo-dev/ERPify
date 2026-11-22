<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security\Fixtures;

use Attribute;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Engine fixture: a project-local `IsGranted` subclass.
 *
 * @internal
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class SubclassGrantedFixture extends IsGranted
{
}
