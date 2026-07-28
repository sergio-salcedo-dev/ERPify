<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Http\Infrastructure\Fixtures;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A payload shaped like one an upload-bearing endpoint would declare: a scalar and a file member.
 *
 * The API has no such endpoint today — `POST /banks` is JSON-only and refuses multipart at the argument
 * resolver. This fixture exists so the transport invariant stays testable anyway: without a type the
 * serializer is asked to fill with an `UploadedFile`, the rule
 * {@see \Erpify\Shared\Http\Infrastructure\TransportOnlyUploadedFileDenormalizer} enforces has nothing to
 * fire on, and a guardrail whose test cannot go red is not a guardrail.
 *
 * @internal
 */
final readonly class UploadBearingPayload
{
    public function __construct(
        public string $name,
        public ?UploadedFile $image = null,
    ) {
    }
}
