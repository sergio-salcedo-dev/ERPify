<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Persistence\Infrastructure\AffectedRows;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\PhpSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `\is_int()` lives in {@see AffectedRows} and nowhere else in `api/src`.
 *
 * **What this refuses is one spelling, and saying so precisely is the whole point.** Seven sites across four
 * adapters independently reached for the same fallback over the `mixed` that
 * `Doctrine\ORM\AbstractQuery::execute()` returns — six spelled `\is_int($affected) ? $affected : 0` and the
 * seventh `\is_int($affected) && $affected > 0` — minting the value its callers read as evidence
 * that nothing needed erasing. A bulk DML statement ALWAYS yields a count, so a default there can only be
 * invented; that is what separates it from the `\is_numeric($x) ? (int) $x : 0` sites over `fetchOne()`,
 * where `false` means "no row" and the default is an answer rather than a fabrication. Those stay.
 *
 * **This is a floor on accidents and nothing more, and two stronger gates were tried and measured broken
 * before it.** A scanner attributing statements to their enclosing method carried six defects of its own —
 * `use function is_int;` collapsed a whole class into one phantom record. Counting DQL statements against
 * narrowings per file then failed as an IDEA rather than an implementation: it went green on a file that
 * narrowed a DBAL count and fabricated its DQL one, red on the same file once BOTH were narrowed, red on the
 * single-helper shape that is the cleanest possible compliance, and blind to `->delete(self::ENTITY, …)` —
 * an idiom this repository already uses. A rule that rewards the defect and refuses the repair is worse than
 * no rule, so what remains claims only what it can hold.
 *
 * **What a green does NOT prove.** It says nothing about `(int) $x`, `$x ?? 0`, an `if`, a `match`, a
 * `getResult()` spelling, or any narrowing that does not spell `is_int`. It cannot see whether a count is
 * CORRECT, or whether the guard's answer is used. The eighth adapter that fabricates by any other means
 * ships green, and review is the only control on that direction — stated here rather than implied closed.
 *
 * @internal
 */
#[CoversNothing]
final class IntNarrowingConfinementGateTest extends TestCase
{
    /** The narrowing whose hand-rolled form fabricated an erasure count seven times over. */
    private const string NARROWING = 'is_int(';

    /** Its one sanctioned home, matched by file rather than by class name so a rename moves with it. */
    private const string HOME = 'Shared/Persistence/Infrastructure/AffectedRows.php';

    public function testTheHandRolledIntNarrowingLivesOnlyInTheSharedGuard(): void
    {
        $this->assertSame([], $this->filesNarrowingOutsideTheGuard(), \sprintf(
            'Only %s may ask whether a store result is an int. Everywhere else that question is answered '
            . 'with a fabricated default — `? $affected : 0` minted the one value the GDPR erasure path '
            . 'reads as "there was nothing to erase". Route the result through AffectedRows::from(), which '
            . 'raises instead of inventing.',
            self::HOME,
        ));
    }

    /**
     * The anti-vacuity half: the rule above is satisfied trivially by a tree that no longer has a guard at
     * all, and a confinement nobody is confined to proves nothing.
     */
    public function testTheGuardStillExistsAndIsStillReached(): void
    {
        $narrowings = 0;
        $callers = [];

        foreach ($this->sources() as $path => $code) {
            $calls = \substr_count(PhpSource::withoutComments($code), 'AffectedRows::from(');

            if ($calls > 0 && !\str_ends_with($path, self::HOME)) {
                $callers[] = $path;
                $narrowings += $calls;
            }
        }

        $this->assertGreaterThanOrEqual(4, \count($callers), 'Adapters stopped routing through the guard.');
        $this->assertGreaterThanOrEqual(8, $narrowings, 'Statement results stopped reaching the guard.');

        // Confinement is satisfied by a tree where the narrowing exists NOWHERE, which is the vacuous
        // reading of this whole rule — measured green until this line, by deleting the guard's own check.
        $this->assertStringContainsString(
            self::NARROWING,
            $this->sourceOf(self::HOME),
            'The guard no longer narrows, so confining the narrowing to it says nothing.',
        );
    }

    /**
     * The stripping is what keeps a file that DOCUMENTS the defect from being read as committing it — this
     * repository has already shipped a sweep that pointed at the two files explaining the danger. No source
     * outside the guard names it today, so the tree cannot exercise it and a synthetic pair must.
     */
    public function testAFileThatOnlyDOCUMENTSTheNarrowingIsNotAnOffender(): void
    {
        $documented = "<?php\n\n/** Never write `\\is_int(\$x) ? \$x : 0` here. */\n"
            . "final class D { }\n";
        $committed = "<?php\n\nfinal class C { public function f(mixed \$x): int\n"
            . "{ return \\is_int(\$x) ? \$x : 0; } }\n";

        $this->assertSame(
            ['Committed.php'],
            $this->offendersIn(['Documented.php' => $documented, 'Committed.php' => $committed]),
        );
    }

    /**
     * @return list<string>
     */
    private function filesNarrowingOutsideTheGuard(): array
    {
        return $this->offendersIn($this->sources());
    }

    /**
     * @param iterable<string, string> $sources raw PHP, keyed by path
     *
     * @return list<string>
     */
    private function offendersIn(iterable $sources): array
    {
        $offenders = [];

        foreach ($sources as $path => $source) {
            $code = PhpSource::withoutComments($source);

            if (\str_contains($code, self::NARROWING) && !\str_ends_with($path, self::HOME)) {
                $offenders[] = $path;
            }
        }

        return $offenders;
    }

    private function sourceOf(string $suffix): string
    {
        foreach ($this->sources() as $path => $source) {
            if (\str_ends_with($path, $suffix)) {
                return PhpSource::withoutComments($source);
            }
        }

        $this->fail(\sprintf('%s is gone; the narrowing has no home to be confined to.', $suffix));
    }

    /**
     * Every source file as RAW text, keyed by its path relative to `api/src`. Consumers strip the comments —
     * the guard's own class explains the defect by spelling it, and a file that documents the danger must
     * never be read as committing it.
     *
     * @return iterable<string, string>
     */
    private function sources(): iterable
    {
        $root = ApiSourceFiles::root();

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $source = \file_get_contents($file->getPathname());

            // Raised rather than asserted: an unreadable file must not silently leave the sweep, and an
            // assertion per file would drown the count that says how much this gate actually checked.
            if (false === $source) {
                throw new RuntimeException(
                    \sprintf('Could not read %s; the sweep would skip it.', $file->getPathname()),
                );
            }

            yield \ltrim(\str_replace($root, '', $file->getPathname()), '/') => $source;
        }
    }
}
