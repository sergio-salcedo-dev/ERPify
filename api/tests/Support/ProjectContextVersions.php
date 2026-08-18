<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use JsonException;
use RuntimeException;

/**
 * Derivation rules for `api/.project-context-versions`: the registry binding every version
 * `docs/project-context.md` claims to the manifest entry that owns it.
 *
 * The page's whole remaining purpose is telling an agent what its training data gets wrong about this
 * stack, so a version on it is load-bearing and a stale one is worse than none — it is asserted with the
 * same confidence as a true one, and the reader has no way to tell them apart. Measured over that page's
 * history: ten drifted claims against zero wrong version numbers, which is why the numbers earned a gate
 * and the normative prose was moved to the docs that own it instead.
 *
 * A registry entry binds a **token**, not a bare number. Binding "4" would make the staleness direction a
 * tautology, since that substring appears in any page of prose — the check would pass over a page that no
 * longer mentions Behat at all. The version is the token's last whitespace-separated word.
 *
 * These rules are pinned against fixtures by `ProjectContextVersionRulesGateTest` (the comparison) and
 * `ProjectContextRegistryRulesGateTest` (parsing and defect reporting); the assertions over the real tree
 * are `ProjectContextVersionGateTest`. All three live in `tests/Unit/Shared/Architecture/`.
 *
 * @phpstan-type RegistryEntry array{manifest: string, path: string, token: string, version: string}
 *
 * @internal test support
 */
final class ProjectContextVersions
{
    public const string REGISTRY = '.project-context-versions';

    public const string PAGE = 'docs/project-context.md';

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

        if (!\str_contains($page, $entry['token'])) {
            return \sprintf(
                '"%s" no longer appears on %s. Either the page reworded the claim and this line was left '
                . 'behind, or the claim is gone and so should the line — a registry entry nobody makes is '
                . 'not a guard, it is a decoration that reports green.',
                $entry['token'],
                self::PAGE,
            );
        }

        return null;
    }

    /**
     * A declaration satisfies a claim when it *starts* with it at a version boundary.
     *
     * The boundary is what keeps the comparison honest: a bare prefix match lets the claim "1" pass
     * against "16.3.0", so every one-digit claim on the page would be unfalsifiable.
     */
    public static function satisfies(string $declared, string $version): bool
    {
        $normalised = \ltrim($declared, '^~>=< ');

        return 1 === \preg_match('/^' . \preg_quote($version, '/') . '(\D|$)/', $normalised);
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
