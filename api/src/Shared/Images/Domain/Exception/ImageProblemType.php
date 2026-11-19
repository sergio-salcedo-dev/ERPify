<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

/**
 * The Problem Details `type` values an image write answers with. A client maps exactly this set
 * (`docs/api-error-contract.md`, "Image writes"), so a value is added here and in that table together.
 */
final readonly class ImageProblemType
{
    /** Empty body, or bytes the decoder cannot read: 422. */
    public const string INVALID = 'image-invalid';

    /** Over the byte cap, or over the decoded pixel and dimension limits: 413. */
    public const string TOO_LARGE = 'image-too-large';

    /** A format outside the allowlist, or one that contradicts the declared media type: 415. */
    public const string UNSUPPORTED_TYPE = 'image-unsupported-type';

    /** `Content-Length` missing, or not equal to the bytes read: 411. */
    public const string LENGTH_REQUIRED = 'image-length-required';

    /** The origin of a derivative is absent, not readable yet, or erased: 409. */
    public const string ORIGIN_NOT_LIVE = 'image-origin-not-live';

    private function __construct()
    {
    }
}
