<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Single source for reading a gate allowlist file into its entries. Each drift gate exempts
 * specific source files via a `.<gate>-allowlist` at the api root (event-dispatch, error-contract,
 * …); the parsing — read the file, skip blank lines and `#` comments, trim the rest — is identical
 * boilerplate. Centralising it here keeps the gates from drifting apart on what counts as an entry.
 *
 * Read only: each caller resolves its own allowlist path and layers its own matching on top.
 *
 * @internal test support
 */
final class AllowlistFile
{
    /**
     * Trimmed, non-comment lines of the allowlist at `$path`. A missing or unreadable file yields
     * an empty list rather than throwing, so a gate with no exemptions behaves like one with an
     * empty allowlist.
     *
     * @return list<string>
     */
    public static function entries(string $path): array
    {
        if (!\is_file($path)) {
            return [];
        }

        $raw = \file_get_contents($path);

        if (false === $raw) {
            return [];
        }

        $entries = [];

        foreach (\preg_split('/\R/', $raw) ?: [] as $line) {
            $trimmed = \trim($line);

            if ('' === $trimmed) {
                continue;
            }

            if (\str_starts_with($trimmed, '#')) {
                continue;
            }

            $entries[] = $trimmed;
        }

        return $entries;
    }
}
