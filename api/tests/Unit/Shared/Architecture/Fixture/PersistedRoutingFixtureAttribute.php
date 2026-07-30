<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture;

use Attribute;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * A SUBCLASS of {@see AsMessage}. Symfony reads routing attributes with `ReflectionAttribute::IS_INSTANCEOF`
 * (`SendersLocator::getTransportNamesFromAttribute()`), so a project-specific attribute extending `AsMessage`
 * routes exactly like the real one — while a gate reflecting on `AsMessage` without that flag sees nothing.
 *
 * @internal
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class PersistedRoutingFixtureAttribute extends AsMessage
{
}
