<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Application;

use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Exception\InvalidImageException;
use Erpify\Shared\Storage\Application\Dto\StoredObjectWriteResult;
use Erpify\Shared\Storage\Application\Port\ObjectStoragePort;
use Erpify\Shared\Storage\Domain\ContentAddressableObjectKey;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Writes normalized raster bytes to the object store under {@see ContentAddressableObjectKey}.
 * Use from any module that stores Flysystem-backed images (Bank, Product, …).
 */
final class StoredImageObjectWriter
{
    public function __construct(
        private readonly ImageNormalizer $normalizer,
        private readonly ObjectStoragePort $objectStorage,
    ) {
    }

    public function storeFromUploadedFile(UploadedFile $file, string $invalidImageFormField = 'stored_object'): StoredObjectWriteResult
    {
        try {
            $normalized = $this->normalizer->normalize($file);
        } catch (InvalidImageException $e) {
            throw new InvalidImageException($e->getMessage(), $invalidImageFormField);
        }

        $key = ContentAddressableObjectKey::fromContentHash($normalized->contentHash);

        if (!$this->objectStorage->exists($key)) {
            $this->objectStorage->write($key, $normalized->bytes);
        }

        return new StoredObjectWriteResult(
            $key,
            $normalized->mimeType,
            \strlen($normalized->bytes),
            $normalized->contentHash,
        );
    }
}
