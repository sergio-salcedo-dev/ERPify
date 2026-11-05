<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Storage;

/**
 * The two namespaces an object passes through: `Staging` holds bytes whose upload is not yet committed and
 * `Final` holds the object a committed document owns. Named in the port so an operation says which one it
 * means — a sweep that deletes an aborted upload's staged object must never be able to reach the final one.
 */
enum StorageArea: string
{
    case Staging = 'staging';
    case Final = 'final';
}
