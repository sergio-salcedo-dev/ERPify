<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Whether a path a registry DECLARES can be read at all — the check every declarative gate needs before it
 * reads the artefact a line points at, and the one that decides what a declaration is allowed to name.
 *
 * Centralised because the three failure modes are identical wherever a registry names a file, and each was
 * paid for once: a traversal, a shape that is not the kind of artefact the rule reads, and a DIRECTORY —
 * which `file_exists()` accepts, so declaring one would silence every check downstream with nothing written
 * at all.
 *
 * @internal test support
 */
final class DeclaredPath
{
    /**
     * Why `$path` cannot be read as a declared `.{$extension}` artefact under `$prefix`, or `null` when it
     * can.
     */
    public static function defectIn(string $apiRoot, string $path, string $prefix, string $extension): ?string
    {
        if (\str_contains($path, '..')) {
            return \sprintf('"%s" escapes the repository with ".."', $path);
        }

        if (!\str_starts_with($path, $prefix) || !\str_ends_with($path, '.' . $extension)) {
            return \sprintf('"%s" is not a .%s file under %s', $path, $extension, $prefix);
        }

        $absolute = $apiRoot . '/' . $path;

        // Reported apart from plain absence because they are different mistakes with different fixes, and
        // because a caller that pins the message would otherwise be unable to tell whether its fixture
        // directory is still there — a check whose input has silently vanished passes for the wrong reason.
        if (\is_dir($absolute)) {
            return \sprintf('"%s" is a directory, not a file', $path);
        }

        if (!\is_file($absolute)) {
            return \sprintf('"%s" does not exist', $path);
        }

        return null;
    }
}
