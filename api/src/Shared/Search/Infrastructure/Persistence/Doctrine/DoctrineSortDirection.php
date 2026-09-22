<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine;

use Erpify\Shared\Search\Domain\SortDirection;
use SortDirection as NativeSortDirection;

/**
 * Translates the search contract's sort direction into the one Doctrine's query builder takes.
 *
 * The target is PHP's own `SortDirection`, native from 8.6 and supplied by symfony/polyfill-php86
 * below it. It lives here rather than on the domain enum because the reason it is needed is the
 * ORM's signature: passing the backing string is deprecated since doctrine/orm 3.7 and removed in
 * 4. Nothing in the domain should have to know that.
 */
final class DoctrineSortDirection
{
    /**
     * A namespace for one translation, never a value. `services.yaml` autodiscovers `../src/`, so a
     * public constructor here registers an instantiable service nothing injects.
     */
    private function __construct()
    {
    }

    public static function from(SortDirection $direction): NativeSortDirection
    {
        return match ($direction) {
            SortDirection::ASC => NativeSortDirection::Ascending,
            SortDirection::DESC => NativeSortDirection::Descending,
        };
    }
}
