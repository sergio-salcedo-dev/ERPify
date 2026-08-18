<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use JsonException;
use RuntimeException;

/**
 * Derivation rules for `api/.project-context-versions`: the registry binding every version
 * `docs/project-context.md` claims to the manifest entry that owns it.
 *
 * A version on that page is load-bearing and a stale one is worse than none — it is asserted with the
 * same confidence as a true one, and the reader has no way to tell them apart. That is not hypothetical:
 * the commit before this gate existed (#746) corrected TWELVE second-column version numbers at once, and
 * fourteen have been corrected over the page's history. The page states normative rules too, and those no
 * cheap check can falsify; the versions are the part that can be, so they are the part gated here.
 *
 * A registry entry binds a **token**, not a bare number. Binding "4" would make the staleness direction a
 * tautology, since that substring appears in any page of prose — the check would pass over a page that no
 * longer mentions Behat at all. The version is the token's last whitespace-separated word.
 *
 * The universe of claims is derived rather than trusted: {@see claimsIn()} reads the second column of the
 * page's own tables, so a version added to the page with no registry line fails instead of passing in
 * silence. A claim nothing can own — pinned by digest, or reached only transitively — is declared against
 * the {@see UNBOUND} manifest with the reason, which is a statement rather than an omission.
 *
 * These rules are pinned against fixtures one subject at a time — `ProjectContextManifestRulesGateTest`
 * (what a declared constraint satisfies), `ProjectContextPageRulesGateTest` (which words are claims and
 * which token covers one) and `ProjectContextRegistryRulesGateTest` (how a line parses and how a defect is
 * reported); the assertions over the real tree are `ProjectContextVersionGateTest`. All four live in
 * `tests/Unit/Shared/Architecture/`.
 *
 * @phpstan-type RegistryEntry array{manifest: string, path: string, token: string, version: string}
 * @phpstan-type PageClaim array{claim: string, line: int}
 *
 * @internal test support
 */
final class ProjectContextVersions
{
    public const string REGISTRY = '.project-context-versions';

    public const string PAGE = 'docs/project-context.md';

    /**
     * The manifest name a claim carries when no manifest can own it. The middle column then holds the
     * reason instead of a dotted path, so the exemption is readable at the line that grants it.
     */
    public const string UNBOUND = 'unbound';

    /**
     * @return list<RegistryEntry>
     */
    public static function entriesIn(string $registry): array
    {
        $contents = \is_file($registry) ? \file_get_contents($registry) : false;

        if (false === $contents) {
            throw new RuntimeException(\sprintf('The version registry is unreadable: %s', $registry));
        }

        $entries = [];

        foreach (\explode("\n", $contents) as $line) {
            $line = \trim($line);

            if ('' === $line || \str_starts_with($line, '#')) {
                continue;
            }

            $entries[] = self::parse($line);
        }

        return $entries;
    }

    /**
     * Why `$entry` does not hold, or `null` when it does.
     *
     * Both directions are one method because a caller that could ask for only one of them would
     * eventually ask for only the cheap one.
     *
     * @param RegistryEntry $entry
     */
    public static function defectIn(string $repoRoot, array $entry, string $page): ?string
    {
        if (self::UNBOUND === $entry['manifest']) {
            return self::absentFromPage($page, $entry['token']);
        }

        $declared = self::declaredConstraint($repoRoot . '/' . $entry['manifest'], $entry['path']);

        if (null === $declared) {
            return \sprintf(
                '%s :: %s addresses nothing in that manifest. A registry line pointing at an absent key '
                . 'guards nothing, and the page keeps asserting the version regardless.',
                $entry['manifest'],
                $entry['path'],
            );
        }

        if (!self::satisfies($declared, $entry['version'])) {
            return \sprintf(
                'the page says "%s" but %s :: %s declares "%s"',
                $entry['token'],
                $entry['manifest'],
                $entry['path'],
                $declared,
            );
        }

        return self::absentFromPage($page, $entry['token']);
    }

    /**
     * The staleness direction, shared by bound and unbound entries: a line guarding a claim nobody makes
     * is not a guard, it is a decoration that reports green.
     */
    private static function absentFromPage(string $page, string $token): ?string
    {
        if (\str_contains(self::withoutEmphasis($page), self::withoutEmphasis($token))) {
            return null;
        }

        return \sprintf(
            '"%s" no longer appears on %s. Either the page reworded the claim and this line was left '
            . 'behind, or the claim is gone and so should the line — a registry entry nobody makes is '
            . 'not a guard, it is a decoration that reports green.',
            $token,
            self::PAGE,
        );
    }

    /**
     * A declaration satisfies a claim when the version it *begins at* starts with the claim, at a version
     * boundary.
     *
     * Two things keep the comparison honest, and each was measured failing without the other. The boundary
     * refuses a bare prefix: without it the claim "1" passes against "16.3.0" and every one-digit claim on
     * the page — Behat 4, PHPStan 2, Rector 2, Inversify 8, TypeScript 6 — is unfalsifiable. The operator
     * allowlist refuses a constraint that does not begin at a version at all: stripping the leading
     * punctuation as a charlist inverts `<` and `>`, so `<2.0` and `>2` both satisfied "2" — the page could
     * claim a version its manifest *excludes* and the gate would agree. A union has no single beginning,
     * so it is refused outright rather than read as its first alternative: `^24.15.0 || >=26.0.0` was
     * measured satisfying the claim "24" while the container runs 26.
     */
    public static function satisfies(string $declared, string $version): bool
    {
        $lowerBound = self::lowerBoundOf($declared);

        if (null === $lowerBound) {
            return false;
        }

        return 1 === \preg_match('/^' . \preg_quote($version, '/') . '(\D|$)/', $lowerBound);
    }

    /**
     * The version a constraint begins at, or `null` when it does not begin at one.
     *
     * `^`, `~`, `>=` and `=` all pin a floor and are read; a bare version and a wildcard are their own
     * floor. Everything else — `<`, `<=`, `>`, `!=`, and any union or range — is refused, because a claim
     * the gate cannot place is a claim it must not bless.
     */
    private static function lowerBoundOf(string $declared): ?string
    {
        $declared = \trim($declared);

        foreach (['||', '|', ',', ' - '] as $alternation) {
            if (\str_contains($declared, $alternation)) {
                return null;
            }
        }

        if (1 !== \preg_match('/^(?:\^|~|>=|=)?(\d.*)$/', $declared, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Every version claim the page's tables make: the second column of each table row, which is where the
     * page states what it runs. The third column is prose and the first is a label, so neither is read.
     *
     * A claim is identified by the word carrying the number, not by the whole product name, because a cell
     * writes "Doctrine ORM 3.6" while another writes "jsdom 30" — matching the tail is what lets a registry
     * token spell the product out without the extraction having to guess where the name begins.
     *
     * Measured over the page at 406 lines: 33 claims, no false positives. It reads a version glued to its
     * subject by whitespace only, so `node:26-trixie` and `dunglas/frankenphp:1-php8.5` are outside the
     * universe however plainly they are stated; the registry header names that gap rather than hiding it.
     *
     * @return list<PageClaim>
     */
    public static function claimsIn(string $page): array
    {
        $claims = [];

        foreach (\explode("\n", $page) as $offset => $line) {
            $line = \trim($line);

            if (!\str_starts_with($line, '|')) {
                continue;
            }

            $cells = \explode('|', \trim($line, '|'));

            if (\count($cells) < 2) {
                continue;
            }

            $subject = \trim($cells[1]);

            if (1 === \preg_match('/^[-: ]+$/', $subject)) {
                continue;
            }

            $subject = self::withoutEmphasis($subject);

            \preg_match_all('/(@?[A-Za-z][A-Za-z0-9.\-\/+]*)\s+(\d+(?:\.\d+)*)/', $subject, $hits, PREG_SET_ORDER);

            foreach ($hits as $hit) {
                $claims[] = ['claim' => $hit[1] . ' ' . $hit[2], 'line' => $offset + 1];
            }
        }

        return $claims;
    }

    /**
     * Whether a registry token makes the page's claim.
     *
     * Tail match, not equality: the registry token may carry a longer product name than the cell's
     * version-bearing word ("Doctrine ORM 3.6" covers "ORM 3.6"), but it may never carry a *different*
     * version, and the boundary breaks on a word — without it "happy-jsdom 30" would cover "jsdom 30",
     * a different package entirely.
     *
     * It strips emphasis for the same reason {@see absentFromPage()} does: a token written to mirror the
     * page (`` `eslint-config-next` 16.2 ``) must not pass one leg of the gate and fail the other.
     */
    public static function coversClaim(string $token, string $claim): bool
    {
        $token = (string) \preg_replace('/\s+/', ' ', \trim(self::withoutEmphasis($token)));
        $claim = self::withoutEmphasis($claim);

        return $token === $claim || \str_ends_with($token, ' ' . $claim);
    }

    /**
     * The page with its inline emphasis removed, so both halves of the gate read the same text.
     *
     * They must, and a raw comparison was measured proving they did not: the page writes
     * `` `eslint-config-next` 16.2 ``, whose backticks sit INSIDE the claim, so the extraction saw a
     * claim the staleness check could not find and the registry could not hold a line satisfying both.
     */
    private static function withoutEmphasis(string $text): string
    {
        return \str_replace(['**', '`'], '', $text);
    }

    /**
     * The raw constraint a manifest declares at a dotted path, or `null` when the path addresses nothing.
     */
    public static function declaredConstraint(string $manifest, string $path): ?string
    {
        $contents = \is_file($manifest) ? \file_get_contents($manifest) : false;

        if (false === $contents) {
            return null;
        }

        try {
            $node = \json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        foreach (\explode('.', $path) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return \is_string($node) ? $node : null;
    }

    /**
     * @return RegistryEntry
     */
    private static function parse(string $line): array
    {
        $halves = \explode('=>', $line);

        if (2 !== \count($halves)) {
            throw new RuntimeException(\sprintf('A registry line is not "<manifest> :: <path> => <token>": %s', $line));
        }

        $source = \explode('::', $halves[0]);

        if (2 !== \count($source)) {
            throw new RuntimeException(\sprintf('A registry line names no "<manifest> :: <path>": %s', $line));
        }

        $token = \trim($halves[1]);
        $words = \preg_split('/\s+/', $token);

        if (false === $words || [] === $words || '' === $token) {
            throw new RuntimeException(\sprintf('A registry line declares an empty token: %s', $line));
        }

        return [
            'manifest' => \trim($source[0]),
            'path' => \trim($source[1]),
            'token' => $token,
            'version' => $words[\count($words) - 1],
        ];
    }
}
