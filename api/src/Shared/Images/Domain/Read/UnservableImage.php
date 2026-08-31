<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Read;

use RuntimeException;

/**
 * The stored object cannot be served, and the fault is this deployment's rather than the caller's.
 *
 * **Deliberately not a `DomainException` and deliberately unmarked**, so it falls to the factory's default
 * arm and answers 500 `unhandled-exception` — reaching the error reporter, which is where a broken
 * deployment belongs. Giving it `ServiceUnavailable` would tell the client to retry something no retry
 * fixes; giving it `NotFound` would report a missing image where there is a corrupt one.
 *
 * It shares its status and its `type` with a permanent storage failure, and that is a decision rather than
 * an accident: to a client both mean "the server is broken and retrying does not help". The two are
 * separated on the observability axis, where an operator reads them, not on the wire.
 *
 * Neither the identifier nor the digest is quoted in the message — the reason its siblings record.
 */
final class UnservableImage extends RuntimeException
{
    private function __construct(private readonly ReadFailureCategory $category, string $message)
    {
        parent::__construct($message);
    }

    public static function becauseTheDigestDoesNotMatch(): self
    {
        return new self(
            ReadFailureCategory::DigestMismatch,
            'The stored bytes do not hash to the digest the image row attests.',
        );
    }

    public static function becauseItExceedsTheServingBudget(): self
    {
        return new self(
            ReadFailureCategory::ObjectTooLarge,
            'The image row declares a byte size above the serving budget of this deployment.',
        );
    }

    public function readFailure(): ReadFailureCategory
    {
        return $this->category;
    }
}
