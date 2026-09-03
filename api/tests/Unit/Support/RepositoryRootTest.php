<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Support;

use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every gate that reads its subject through this resolver fails when it answers `null`. That makes a wrong
 * marker a way for all of them to stop checking at once, loudly in CI and silently nowhere — so what is
 * worth pinning is the marker itself. The population is deliberately left unnumbered: a count written here
 * is one nothing recomputes, so it states the blast radius correctly only until the next gate adopts this.
 *
 * **`.git` is the marker that must never come back.** Measured in this tree: it is a DIRECTORY in the
 * primary checkout and a regular FILE in every linked worktree. CLAUDE.md requires feature work to happen
 * in a worktree, so `is_dir($candidate . '/.git')` resolves where nobody works and refuses where everybody
 * does. A file marker has no such split, which is why the assertion below is about the KIND of each marker
 * and not merely about its presence.
 *
 * What this cannot do is exercise the resolution against a synthetic tree: the candidates come from
 * `__DIR__`, so there is no seam to point at a fixture. It therefore asserts properties of the real
 * resolution plus the shape of the declared markers, and a marker that exists here but not in some other
 * checkout would pass — the plural list is the mitigation, not this test.
 *
 * @internal
 */
#[CoversClass(RepositoryRoot::class)]
final class RepositoryRootTest extends TestCase
{
    #[Test]
    public function itResolvesTheRootThatHoldsThisApi(): void
    {
        $root = RepositoryRoot::path();

        $this->assertIsString(
            $root,
            'No candidate carried a marker, so every gate reading through this would fail at once. '
            . 'Inside the container the root comes from the read-only `./` bind mount at /app/repo.',
        );
        // `compose.dev.yaml` and not `api/composer.json`: measured inside the container, the api tree and
        // `docs/` exist under BOTH candidates, so asserting on either would pass while `path()` answered the
        // wrong one — every gate reading through this would then fail blaming the bind mount. This is the
        // file that tells the two apart.
        $this->assertFileExists(
            $root . '/compose.dev.yaml',
            'The resolved directory is not the root of THIS repository. A marker matching some other '
            . 'directory would point every gate at a tree it was not written against.',
        );
    }

    /**
     * A directory marker is the `.git` defect by another name, so the kind is asserted rather than the
     * existence. Read through reflection because the list is private: a copy of it here would assert that
     * a literal equals itself.
     */
    #[Test]
    public function everyMarkerItDeclaresIsAFileAndNotADirectory(): void
    {
        $root = RepositoryRoot::path();

        $this->assertIsString($root);

        $markers = (new ReflectionClass(RepositoryRoot::class))->getConstant('MARKERS');

        $this->assertIsArray($markers);
        $this->assertNotEmpty($markers, 'With no markers the resolver answers null for every candidate.');

        // Named rather than left to the kind check below, which cannot see it from here: in THIS checkout
        // `.git` is a regular file, so a marker list containing it would satisfy every other assertion in
        // this method and still refuse to resolve in a plain checkout, where it is a directory.
        $this->assertNotContains(
            '.git',
            $markers,
            '`.git` is a directory in the primary checkout and a regular file in every linked worktree, so '
            . 'as a marker it resolves in one and refuses in the other. Use a root file that is a file in '
            . 'both.',
        );

        foreach ($markers as $marker) {
            $this->assertIsString($marker);
            $this->assertFileExists($root . '/' . $marker);
            $this->assertDirectoryDoesNotExist(
                $root . '/' . $marker,
                \sprintf(
                    '%s is a directory at the repository root. A directory marker is how `.git` behaves — '
                    . 'present in the primary checkout, a plain file in every linked worktree — so it '
                    . 'resolves where nobody works and refuses where the work happens.',
                    $marker,
                ),
            );
        }
    }
}
