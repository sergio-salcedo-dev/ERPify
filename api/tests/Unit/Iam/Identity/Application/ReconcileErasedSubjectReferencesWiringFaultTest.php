<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\PersonReferenceProbeFailed;
use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedPersonResourceReferences;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The faults that happen BEFORE any read, which is what makes them easy to leave outside the wrapper.
 *
 * The sources arrive as `!tagged_iterator`, which compiles to a generator that instantiates each one as the
 * loop reaches it — so "assembling the collection" and "asking a source which axis it covers" are executable
 * steps that can throw, at a point that looks like plain iteration. Left unwrapped they leave the CLI with
 * `FAILURE`, the code reserved for "a person reference survived its erasure", on a run that established
 * nothing about any subject. The reads are covered by the sibling class; these are the two that are not
 * reads at all.
 *
 * @internal
 */
#[CoversClass(ReconcileErasedSubjectReferences::class)]
final class ReconcileErasedSubjectReferencesWiringFaultTest extends TestCase
{
    #[Test]
    public function itWrapsASourceThatCannotEvenNameItsAxis(): void
    {
        // `axis()` is asked for outside every read. Every implementation in the tree answers from a literal
        // today, but the contract does not promise it and its gate reaches the uninitialised form only, so a
        // source that computes its key is admissible — and would raise here.
        $source = $this->createStub(PersonReferenceSource::class);
        $source->method('axis')->willThrowException(new RuntimeException('the axis key is unavailable'));

        $reconciler = new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences([]),
            [$source],
            new InMemoryLiveIdentityDirectory(),
        );

        $this->expectException(PersonReferenceProbeFailed::class);
        $reconciler->unreconciledReferences();
    }

    #[Test]
    public function itWrapsAFailureRaisedWhileTheWiredCollectionIsStillBeingBuilt(): void
    {
        // A source whose dependencies cannot be resolved throws from the `foreach` statement itself, which is
        // why iterating the collection bare put this fault outside every guard in the use case.
        $unbuildable = (static function (): iterable {
            yield from [];

            throw new RuntimeException('the source could not be constructed');
        })();

        $reconciler = new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences([]),
            $unbuildable,
            new InMemoryLiveIdentityDirectory(),
        );

        $this->expectException(PersonReferenceProbeFailed::class);
        $reconciler->unreconciledReferences();
    }
}
