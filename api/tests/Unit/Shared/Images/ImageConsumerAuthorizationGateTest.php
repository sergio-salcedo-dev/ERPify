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
 * `GET /api/v1/images/{imageId}` carries no `#[IsGranted]`, and this is what stops that staying true by
 * accident once it stops being safe.
 *
 * **The decision is the epic's and is not reopened here.** A session is the whole authorization story for
 * this slice: the module holds no owner, cannot tell a company logo from a person's avatar, and there is no
 * consumer relation to vote on — so a voter would be a permission invented ahead of the thing it governs
 * (`epics-images.md`, decision 3 and item 17 of its firewall). What makes that defensible is the SECOND
 * half of the same decision, that the first real consumer brings its own authorization policy.
 *
 * **That second half was prose, and prose is not a control.** An external security review named the exact
 * failure it permits: the first consumer wired without an owner check turns a documented provisional
 * frontier into a silent cross-user read, and nothing in the tree would have gone red. The bot's own
 * suggested fix — gating the route behind a permission granted to no role — was measured and refused: it
 * answers 403 to every caller, which does not defer the decision, it deletes the slice.
 *
 * So the tripwire is on the PRECONDITION rather than on the route. While no aggregate outside this module
 * references an image, the frontier is exactly as wide as the epic says it is. The moment one does, this
 * goes red in the diff that introduces it — which is the diff where the authorization question has an
 * answer, and the only one where it can be asked honestly.
 *
 * **Blind spots, stated because a green here is narrower than it reads.** It matches a property NAME ending
 * in `ImageId` — the two shapes the epic names (`Bank.logoImageId`, `User.avatarImageId`) and anything
 * spelled like them. A consumer holding the value under another name, in a join table, in a JSON column, or
 * reached through a service rather than a field, is invisible. It is a floor on the accidental case, never a
 * ceiling on the deliberate one, and it says nothing about whether an authorization policy that DOES arrive
 * is any good.
 *
 * @internal
 */
#[CoversNothing]
final class ImageConsumerAuthorizationGateTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../../../src';

    /** The module that owns the identifier; a reference inside it is not a consumer relation. */
    private const string OWNING_MODULE = 'Shared/Images';

    #[Test]
    public function noAggregateOutsideTheModuleReferencesAnImageWhileTheRouteHasNoAuthorizationPolicy(): void
    {
        $consumers = [];

        foreach ($this->phpFilesUnderSrc() as $file) {
            $relative = \str_replace('\\', '/', \substr($file->getPathname(), \strlen($this->srcRoot()) + 1));

            if (\str_starts_with($relative, self::OWNING_MODULE . '/')) {
                continue;
            }

            $contents = (string) \file_get_contents($file->getPathname());

            if (1 === \preg_match('/\$[a-z][A-Za-z0-9_]*ImageId\b/', $contents)) {
                $consumers[] = $relative;
            }
        }

        \sort($consumers);

        $this->assertSame([], $consumers, \sprintf(
            "An aggregate outside Shared/Images now references an image:\n  %s\n\n"
            . "`GET /api/v1/images/{imageId}` carries no `#[IsGranted]`, and the epic's argument for that is "
            . 'that no consumer relation exists to vote on. This is the change that ends that argument, so '
            . "it is the change that has to answer it:\n"
            . "  - decide whether this consumer's images are person-denoting, and\n"
            . '  - give the route an authorization policy, or record in `epics-images.md` why this '
            . "particular consumer still does not need one.\n"
            . 'Deleting this test is not one of the options.',
            \implode("\n  ", $consumers),
        ));
    }

    /**
     * Without this the assertion above is satisfied by a walk that found no files at all — a moved module or
     * a broken path reads exactly like a clean tree.
     */
    #[Test]
    public function theSweepReachesTheSourceTree(): void
    {
        $this->assertGreaterThan(100, \iterator_count($this->phpFilesUnderSrc()));
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
