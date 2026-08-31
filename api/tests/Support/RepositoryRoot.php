<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * The repository root, for the gates whose subject sits outside the `api/` build context.
 *
 * Two candidates, because the path depends on where the suite runs: from a checkout the root is the parent
 * of `api/`, while inside the container `api/` IS the build context at `/app/api` and the repository arrives
 * separately through the read-only `./` bind mount at `/app/repo` declared in `compose.dev.yaml`.
 *
 * **The marker is a root file, never `.git`.** Measured, both directions: `.git` is a DIRECTORY in the
 * primary checkout and a regular FILE in every linked worktree. CLAUDE.md requires feature work to happen in
 * a worktree, so a probe spelled `is_dir($candidate . '/.git')` would answer in the primary and refuse in
 * exactly the place the work is done — the same shape that once made the adversarial-pass hook (since
 * retired) read a worktree's basename and go silent there.
 *
 * **It answers `null` rather than failing, and that is the point of it being a resolver and not a guard.**
 * What is unreachable differs by caller: a parity gate cannot see one of its two sites, while
 * `ProjectContextVersionGateTest` cannot see `docs/project-context.md` and the dependency manifests it
 * compares. The resolution is shared; the diagnostic belongs to whoever knows what it is missing, and a
 * shared one can only be right for the callers it was written for.
 *
 * @internal test support
 */
final class RepositoryRoot
{
    /**
     * Files the repository root carries in a checkout and in a linked worktree alike. More than one so a
     * single rename cannot turn every caller into a silent refusal; any one of them is enough.
     *
     * @var non-empty-list<string>
     */
    private const array MARKERS = ['compose.yaml', 'Makefile', 'CLAUDE.md'];

    /**
     * The repository root, or `null` when no candidate carries a marker — which inside the container means
     * the bind mount is gone. A caller decides what that costs it; nothing here may turn it into a skip.
     */
    public static function path(): ?string
    {
        foreach (self::candidates() as $candidate) {
            foreach (self::MARKERS as $marker) {
                if (\is_file($candidate . '/' . $marker)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return non-empty-list<string>
     */
    private static function candidates(): array
    {
        $apiRoot = \dirname(__DIR__, 2);

        return [\dirname($apiRoot), \dirname($apiRoot) . '/repo'];
    }
}
