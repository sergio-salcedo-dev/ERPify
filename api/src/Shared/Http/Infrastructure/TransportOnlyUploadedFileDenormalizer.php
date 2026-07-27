<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Refuses to build a {@see File} out of request-body data: an upload reaches a payload from the request's
 * file bag or not at all.
 *
 * A payload that declares a file member is mapped from the merge of the parsed body and `$request->files`,
 * so the member is reachable from either side. Only one of them is a real upload. The other is body data
 * the caller wrote, and a file object is a value the serializer can happily construct from it: `File`
 * takes `path` as a constructor parameter, and on {@see UploadedFile} `originalName` joins it while
 * `test: true` makes `isValid()` answer true without ever consulting `is_uploaded_file()`. A body naming a
 * server path would otherwise be read from disk, stored, and served back — the caller choosing which file,
 * and the `#[Assert\File]` MIME and size checks passing because they inspect the real bytes at that path.
 *
 * The rule is anchored on `File`, not on `UploadedFile`: the vector is constructibility from a path, which
 * `File` already has, so a member typed as any other `File` subclass would otherwise walk straight past
 * this guard into `ObjectNormalizer`. `checkPath: false` makes that variant quieter still, since it does
 * not even raise the `FileNotFoundException` an absent path would.
 *
 * Declining a member the serializer has already resolved to a genuine file object is what keeps real
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

        // No expected types, and the message marked usable: naming a type here would render
        // "This value should be of type file.", which describes the member rather than the refusal, and
        // would bury MESSAGE where nothing can reach it. Left empty, the resolver renders its
        // uninformative fallback and carries MESSAGE in the violation's `hint`, which is where
        // ProblemDetailsFactory picks the wire message up from.
        throw NotNormalizableValueException::createForUnexpectedDataType(
            self::MESSAGE,
            $data,
            [],
            \is_string($path) ? $path : null,
            true,
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
        return !$data instanceof File
            && (File::class === $type || \is_subclass_of($type, File::class));
    }

    /**
     * @return array<class-string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [File::class => false];
    }
}
