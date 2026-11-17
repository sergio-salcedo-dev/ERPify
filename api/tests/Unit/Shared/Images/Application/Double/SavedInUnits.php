<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

/**
 * The unit of work number each image save ran in; a mutable holder because the repository that fills it is readonly.
 *
 * @internal
 */
final class SavedInUnits
{
    /** @var list<int> */
    public array $units = [];
}
