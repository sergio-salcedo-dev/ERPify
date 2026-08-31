<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\Images\Domain\Read\ReadFailureCategory;
use Erpify\Shared\Images\Domain\Read\UnservableImage;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The read path's observability signal, kept apart from the use case that produces it.
 *
 * Two reasons, and the second is the one that would matter even without the first. **Responsibility:** the
 * finder's job is to answer whether an image is servable; deciding what an operator gets to read about a
 * failure is a different question with different constraints — a channel, a level vocabulary, a rule about
 * what may never appear in a context. **Constraint:** those constraints are enforced by gates that key on
 * the class holding the logger, so putting them here makes the class they govern the class that is actually
 * about them.
 *
 * The context carries the operation and the verdict, both from closed enums, and nothing else. No
 * identifier, digest, storage key or byte count: this sink has no TTL and no owner of erasure, so a value
 * put here outlives every erasure the application can perform.
 *
 * **That is a rule about this context, not a claim about the log.** The same failing request writes
 * `/api/v1/images/<imageId>` into the same sink twice by other routes — the `request_uri` of the error
 * responder, whose redaction keeps the path on purpose, and Caddy's access log, which strips only the query
 * string. So the identifier IS in the container log either way, and nothing here changes that. What this
 * rule buys is narrower and still worth having: the OPERATIONAL dimension stays a closed vocabulary, so a
 * query over `failure_category` is bounded and free of caller-supplied values, and the day an `ImageId`
 * denotes a person the erasure obligation lands on the two sinks that already own a path rather than on a
 * third that grew one by habit. `api/.person-reference-policy` classifies `Image::$id` as `non-person`
 * today, which is why none of this is a leak.
 */
final readonly class ReadFailureReporter
{
    public function __construct(
        private LoggerInterface $logger,
        private FailureSignalWindow $window,
    ) {
    }

    /**
     * One method per verdict, each emitting the signal and handing back the failure to throw, so a call site
     * reads `throw $this->reporter->digestMismatch()`.
     *
     * The caller names the CONDITION and this class owns both the label and the operation it is filed
     * under — which keeps the vocabulary in one place and out of the use case, whose subject is whether the
     * image is servable rather than how a failure is described.
     */
    public function objectTooLarge(): UnservableImage
    {
        return $this->report(UnservableImage::becauseItExceedsTheServingBudget(), StorageOperation::Read);
    }

    public function digestMismatch(): UnservableImage
    {
        return $this->report(UnservableImage::becauseTheDigestDoesNotMatch(), StorageOperation::VerifyIntegrity);
    }

    private function report(UnservableImage $failure, StorageOperation $operation): UnservableImage
    {
        $this->emit($operation, $failure->readFailure());

        return $failure;
    }

    /**
     * Gated by {@see FailureSignalWindow} first: all three read-path failures are PERMANENT, so without it
     * the number of lines this deployment writes is chosen by whoever loops the route.
     *
     * Spelled as an explicit `warning()` rather than `log($level, …)`, because the carrier gate that decides
     * which channels can reach the container log classifies by the level in the method NAME and refuses the
     * PSR-3 form outright. Both verdicts are faults of this deployment, so both are `warning`; there is no
     * outcome-level case here, unlike storage, where a confirmed absence is the ordinary answer.
     */
    private function emit(StorageOperation $operation, ReadFailureCategory $verdict): void
    {
        if (!$this->window->admits($operation->value . '|' . $verdict->value)) {
            return;
        }

        try {
            $this->logger->warning('image_read_failure', [
                'operation' => $operation->value,
                'failure_category' => $verdict->value,
            ]);
        } catch (Throwable) {
            // Swallowed by design — observability is never load-bearing for the outcome it reports.
        }
    }
}
