<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Application;

use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Read\ImageNotAvailable;
use Erpify\Shared\Images\Domain\Read\ImageTemporarilyUnavailable;
use Erpify\Shared\Images\Domain\Read\UnservableImage;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Domain\Storage\ImageStorageUnavailable;

/**
 * Read use case (naming category: a lookup by identity is a `Finder`): an {@see ImageId} in, the verified
 * canonical bytes out.
 *
 * **It exists so no adapter talks to both ports.** A controller wired straight to the repository and the
 * storage port would work and would leave the module without the one public signature the architecture scan
 * can watch — and the scan is what keeps a path, a filename or a storage key out of this module's surface.
 *
 * **The order of the four steps is the contract, not an implementation detail.**
 *
 * 1. `findById()` first. `null` is a CONFIRMED absence — a database failure raises — so the 404 it produces
 *    is a statement rather than a guess. It also means an identifier this deployment never stored never
 *    reaches storage at all, which is the measured correction behind the read path's log-volume decision:
 *    the failure signals below need an orphaned row or a broken deployment, not an invented identifier.
 * 2. The serving budget, checked against the size the ROW declares, BEFORE any byte is read. The port
 *    returns the whole object as one string, so an object larger than the process can hold exhausts memory —
 *    and memory exhaustion is a fatal error, not a `Throwable`, so the RFC 9457 pipeline never runs and the
 *    response is not Problem Details at all. Refusing on the declared size turns that into an ordinary 500.
 *    What the guard cannot see is declared with it: `byteSize` is what the row SAYS, not what the object
 *    weighs.
 * 3. `read()`, with the port's three verdicts translated by decreasing specificity.
 * 4. The digest comparison, before anything is handed back. Nothing that fails it is ever served.
 *
 * **On `!==` rather than `hash_equals()` for that comparison.** The sibling verification on the write path
 * argues its plain comparison from there being no remote party; on this path there IS one, so that argument
 * does not travel and is not reused. The conclusion survives on its own terms: both operands are the
 * server's — one read from our row, one derived from our own bytes — and the caller supplies neither and
 * cannot influence either, so there is no secret for a timing difference to leak.
 */
final readonly class CanonicalImageFinder
{
    public function __construct(
        private ImageRepository $images,
        private ImageStorage $storage,
        private ReadFailureReporter $reporter,
        private int $maxServedBytes,
    ) {
    }

    /**
     * A permanent storage failure is deliberately absent from this list: it escapes untranslated, so it
     * answers 500 rather than inviting a retry that cannot help.
     *
     * @throws ImageNotAvailable           the row is absent, or its bytes demonstrably are
     * @throws ImageTemporarilyUnavailable the substrate failed in a retryable way
     * @throws UnservableImage             the object is too large to serve, or fails its own digest
     */
    public function find(ImageId $id): CanonicalImageBytes
    {
        $image = $this->images->findById($id);

        if (!$image instanceof Image) {
            throw ImageNotAvailable::forRequestedImage();
        }

        if ($image->byteSize() > $this->maxServedBytes) {
            throw $this->reporter->objectTooLarge();
        }

        $bytes = $this->readOrTranslate($id);

        if (\hash('sha256', $bytes) !== $image->digest()) {
            throw $this->reporter->digestMismatch();
        }

        return new CanonicalImageBytes($bytes, $image->mediaType(), $image->digest());
    }

    /**
     * Translates the port's vocabulary by DECREASING specificity, and never catches the
     * `ImageStorageException` interface: catching it first would make every arm below unreachable while
     * still compiling. `ImageStorageFailed` appears in no arm on purpose — it is the permanent verdict and
     * escapes untranslated.
     */
    private function readOrTranslate(ImageId $id): string
    {
        try {
            return $this->storage->read($id);
        } catch (ImageBytesNotFound) {
            // The same answer as an absent row, because from outside they are the same fact. The adapter has
            // already emitted its own signal for this one, so nothing is reported here.
            throw ImageNotAvailable::forRequestedImage();
        } catch (ImageStorageUnavailable $unavailable) {
            throw ImageTemporarilyUnavailable::fromStorageFailure($unavailable);
        }
    }
}
