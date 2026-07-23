<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Refuses to build an {@see UploadedFile} out of request-body data: an upload reaches a payload from the
 * request's file bag or not at all.
 *
 * A payload that declares an `UploadedFile` member is mapped from the merge of the parsed body and
 * `$request->files`, so the member is reachable from either side. Only one of them is a real upload. The
 * other is body data the caller wrote, and `UploadedFile` is a value the serializer can happily construct
 * from it: `path` and `originalName` are constructor parameters, and `test: true` makes `isValid()` answer
 * true without ever consulting `is_uploaded_file()`. A body naming a server path would otherwise be read
 * from disk, stored, and served back — the caller choosing which file, and the `#[Assert\File]` MIME and
 * size checks passing because they inspect the real bytes at that path.
 *
 * Declining a member the serializer has already resolved to a genuine `UploadedFile` is what keeps real
 * multipart uploads working: they arrive as the object itself, not as data to build one from.
 *
 * {@see getSupportedTypes()} answers `false` rather than `true` deliberately. `true` means "this decision
 * holds for the type whatever the data is", which lets the serializer cache it and stop asking — and the
 * whole rule here is a question about the data.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 *
 * `$format` and `$type` are fixed by {@see DenormalizerInterface}; this rule needs neither.
 */
final readonly class TransportOnlyUploadedFileDenormalizer implements DenormalizerInterface
{
    private const string MESSAGE = 'Send this file as an upload; a request body cannot describe one.';

    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): never
    {
        $path = $context['deserialization_path'] ?? null;

        throw NotNormalizableValueException::createForUnexpectedDataType(
            self::MESSAGE,
            $data,
            ['file'],
            \is_string($path) ? $path : null,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = [],
    ): bool {
        return !$data instanceof UploadedFile
            && (UploadedFile::class === $type || \is_subclass_of($type, UploadedFile::class));
    }

    /**
     * @return array<class-string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [UploadedFile::class => false];
    }
}
