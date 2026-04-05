<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Domain;

/**
 * Shared Flysystem path for content-addressable blobs referenced by any aggregate (Bank, Product, …).
 * One physical object per hash across the app; multiple DB rows may reference the same hash.
 */
final class ContentAddressableObjectKey
{
    private const PREFIX = 'objects';

    public static function fromContentHash(string $contentHash): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $contentHash)) {
            throw new \InvalidArgumentException('Invalid content hash for stored object key.');
        }

        return self::PREFIX.'/'.$contentHash;
    }
}
