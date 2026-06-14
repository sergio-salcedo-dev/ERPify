<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Application;

use Erpify\Shared\Media\Application\Dto\UploadedImage;
use Erpify\Shared\Media\Application\Port\ImageNormalizer;
use Erpify\Shared\Media\Domain\Exception\InvalidImageException;
use Erpify\Shared\Storage\Application\Dto\StoredObjectWriteResult;
use Erpify\Shared\Storage\Application\Port\StoragePort;
use Erpify\Shared\Storage\Domain\ContentAddressableObjectKey;

/**
 * Writes normalized raster bytes to the object store under {@see ContentAddressableObjectKey}.
 * Use from any module that stores Flysystem-backed images (Bank, Product, …).
 */
final readonly class StoredImageObjectWriter
{
    public function __construct(
        private ImageNormalizer $imageNormalizer,
        private StoragePort $storagePort,
    ) {
    }

    public function store(
        UploadedImage $uploadedImage,
        string $invalidImageFormField = 'stored_object',
    ): StoredObjectWriteResult {
        try {
            $normalized = $this->imageNormalizer->normalize($uploadedImage);
        } catch (InvalidImageException $invalidImageException) {
            throw new InvalidImageException($invalidImageException->getMessage(), $invalidImageFormField);
        }

        $key = ContentAddressableObjectKey::fromContentHash($normalized->contentHash);

        if (!$this->storagePort->exists($key)) {
            $this->storagePort->write($key, $normalized->bytes);
        }

        return new StoredObjectWriteResult(
            $key,
            $normalized->mimeType,
            \strlen($normalized->bytes),
            $normalized->contentHash,
        );
    }
}
