<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Exactly one place under `src` constructs an {@see \Erpify\Shared\Images\Domain\Entity\Image}, and a second
 * one may not appear by accident.
 *
 * **Why a whole gate for that.** The read route serves `Image::mediaType()` verbatim as the response
 * `Content-Type`, on the origin that also serves the PWA, and it is the first route in the tree whose body a
 * browser renders. The entity guarantees only that the value is non-blank; the four-type allowlist lives at
 * the WRITER (`ImagePreflightGuard`, `MediaTypeEncoderFactory`) and the read edge deliberately does not
 * restate it — it inherits the producer's discipline instead. That inheritance is what makes the read edge
 * correct, and until this gate nothing in the tree held it: the coupling existed only as a fact about how
 * many writers happened to exist.
 *
 * So the property pinned here is the one a source gate can actually prove — **no second writer appears
 * silently** — and the ratchet is the point. A new writer is legitimate; what is not legitimate is one
 * arriving without anybody deciding where its media type comes from. Adding a line below is that decision,
 * and it is visible in review, which is the whole mechanism.
 *
 * **What a green does NOT prove, and the gap is the larger half.** Doctrine hydrates without the
 * constructor, so every invariant on `Image` is a WRITE-time invariant and this gate reads code rather than
 * rows. A `media_type` arriving from a data migration, a restore, a hand-written row or a future context
 * writing SQL directly is invisible here and always will be. What bounds THAT is the response itself:
 * `Content-Security-Policy: default-src 'none'; sandbox` and `Cross-Origin-Resource-Policy: same-origin`,
 * which make the body inert whatever the column turns out to say. The residual is recorded in
 * `PRODUCTION_SECURITY_CHECKLIST.md` §7. It is also a text match over `src` alone, so a construction
 * through reflection or a container factory would not be seen, and fixtures and tests are out of scope by
 * construction.
 *
 * @internal
 */
#[CoversNothing]
final class ImageWriterSurfaceGateTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../../../src';

    /**
     * Each entry is a file that may construct an `Image`, with the reason its media type is trustworthy.
     * A new entry is a decision about that value, never a formality.
     */
    private const array WRITERS = [
        // The canonicalization pipeline's own output: the media type comes from `MediaTypeEncoderFactory`,
        // which can only produce one of the four encoders it knows, so the column is closed by construction.
        'Shared/Images/Application/UploadImage.php',
    ];

    #[Test]
    public function onlyTheDeclaredWritersConstructAnImage(): void
    {
        $found = [];

        foreach ($this->phpFilesUnderSrc() as $file) {
            $contents = (string) \file_get_contents($file->getPathname());

            if (1 === \preg_match('/\bnew\s+Image\s*\(/', $contents)) {
                $found[] = \str_replace('\\', '/', \substr($file->getPathname(), \strlen($this->srcRoot()) + 1));
            }
        }

        \sort($found);
        $declared = self::WRITERS;
        \sort($declared);

        $this->assertSame($declared, $found, \sprintf(
            "The set of files constructing an Image changed.\nDeclared: %s\nFound: %s\n"
            . 'The read route serves `Image::mediaType()` straight into a `Content-Type` a browser renders, '
            . 'and the type allowlist lives only at the writer. A new writer must decide where its media '
            . 'type comes from, then add itself to WRITERS with that reason.',
            \implode(', ', $declared),
            \implode(', ', $found),
        ));
    }

    /**
     * Without this the assertion above is satisfied by a walk that found nothing — the same shape as a moved
     * module or a broken path, and it would pass while proving the opposite of what it claims.
     */
    #[Test]
    public function theDeclaredWritersExist(): void
    {
        foreach (self::WRITERS as $writer) {
            $this->assertFileExists($this->srcRoot() . '/' . $writer);
        }
    }

    private function srcRoot(): string
    {
        return (string) \realpath(self::SRC);
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function phpFilesUnderSrc(): iterable
    {
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->srcRoot(),
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        foreach ($files as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                yield $file;
            }
        }
    }
}
