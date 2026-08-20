<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * Reads `api/.artifact-gate-placement` and pairs it with the sweep of {@see ArtifactGateSweep}.
 *
 * The registry says where each artifact gate is placed; this class says whether the tree agrees, in both
 * directions — a gate with no line, a line no gate backs, and a line whose placement the file's own path
 * contradicts. A malformed line throws rather than being skipped: a classification nobody can parse is
 * indistinguishable from a missing one, and the missing direction is the one that lets a gate be filed by
 * copying its neighbours.
 *
 * @internal test support
 *
 * @phpstan-type Placement array{placement: string, subject: ?string, reason: ?string}
 */
final readonly class ArtifactGatePlacements
{
    /** The gate sits in the category home. */
    public const string IN_HOME = 'home';

    /** The gate is scoped to one module and sits mirrored on it. The line names the directory it mirrors. */
    public const string MIRRORED = 'mirrored';

    /** The file sits in the home and its membership in the category is an open question. The line says why. */
    public const string UNDECIDED = 'undecided';

    /** Where a mirror's subject may live. `tests/` is the second mirror axis: test infrastructure. */
    private const array SUBJECT_ROOTS = ['src/', 'tests/'];

    private const string MIRROR_ROOT = 'tests/Unit/';

    /**
     * @param list<string>             $universe       api-relative paths of every artifact gate in the tree
     * @param array<string, Placement> $classification api-relative path => its line
     */
    private function __construct(
        private array $universe,
        private array $classification,
        private string $apiRoot,
    ) {
    }

    public static function over(string $apiRoot, string $registryPath, string $suffix = 'Test.php'): self
    {
        return new self(
            ArtifactGateSweep::over($apiRoot, $suffix),
            self::parse(AllowlistFile::entries($registryPath)),
            $apiRoot,
        );
    }

    /**
     * @param list<string> $lines
     *
     * @return array<string, Placement>
     */
    public static function parse(array $lines): array
    {
        $registry = [];

        foreach ($lines as $line) {
            [$path, $placement] = self::parseLine($line);

            if (isset($registry[$path])) {
                throw new RuntimeException(\sprintf(
                    'Duplicate path in .artifact-gate-placement: "%s". PHP array assignment keeps the later '
                    . 'value, so one of the two classifications would never be read.',
                    $path,
                ));
            }

            $registry[$path] = $placement;
        }

        return $registry;
    }

    /**
     * @return list<string>
     */
    public function universe(): array
    {
        return $this->universe;
    }

    /**
     * @return array<string, Placement>
     */
    public function classification(): array
    {
        return $this->classification;
    }

    /**
     * Gates the sweep found that no line classifies.
     *
     * @return list<string>
     */
    public function unclassified(): array
    {
        return \array_values(\array_diff($this->universe, \array_keys($this->classification)));
    }

    /**
     * Lines the sweep no longer backs — a renamed, deleted or rewritten gate.
     *
     * @return list<string>
     */
    public function stale(): array
    {
        return \array_values(\array_diff(\array_keys($this->classification), $this->universe));
    }

    /**
     * Lines the tree contradicts, path => what the contradiction is.
     *
     * @return array<string, string>
     */
    public function disagreements(): array
    {
        $disagreements = [];

        foreach ($this->classification as $path => $placement) {
            $reason = $this->disagreementFor($path, $placement);

            if (null !== $reason) {
                $disagreements[$path] = $reason;
            }
        }

        return $disagreements;
    }

    /**
     * @param Placement $placement
     */
    private function disagreementFor(string $path, array $placement): ?string
    {
        $inHome = \str_starts_with($path, ArtifactGateSweep::HOME . '/');

        return match ($placement['placement']) {
            self::IN_HOME => $inHome ? null : \sprintf(
                'is classified `home` but does not sit under %s/.',
                ArtifactGateSweep::HOME,
            ),
            self::UNDECIDED => $inHome ? null : \sprintf(
                'is classified `undecided` but does not sit under %s/. Placement is only undecided for a '
                . 'file already in the home; anywhere else it has been decided by being put there.',
                ArtifactGateSweep::HOME,
            ),
            default => $this->mirrorDisagreement($path, (string) $placement['subject'], $inHome),
        };
    }

    private function mirrorDisagreement(string $path, string $subject, bool $inHome): ?string
    {
        if ($inHome) {
            return \sprintf('is classified `mirrored` on "%s" but sits in the category home.', $subject);
        }

        if (!\is_dir($this->apiRoot . '/' . $subject)) {
            return \sprintf('names subject "%s", which is not a directory.', $subject);
        }

        $mirror = $this->mirrorOf($subject);

        if (!\str_starts_with($path, $mirror)) {
            return \sprintf('names subject "%s", whose mirror is %s — the file does not sit there.', $subject, $mirror);
        }

        return null;
    }

    private function mirrorOf(string $subject): string
    {
        foreach (self::SUBJECT_ROOTS as $root) {
            if (\str_starts_with($subject, $root)) {
                return self::MIRROR_ROOT . \substr($subject, \strlen($root)) . '/';
            }
        }

        // Refused at parse time, so this is unreachable from a parsed registry.
        throw new RuntimeException(\sprintf('Subject "%s" is under no known root.', $subject));
    }

    /**
     * @return array{string, Placement}
     */
    private static function parseLine(string $line): array
    {
        // explode always yields at least one element, so the head is a string; the placement is what a
        // truncated line is missing.
        $parts = \array_map(\trim(...), \explode('::', $line));
        $path = \array_shift($parts);
        $placement = \array_shift($parts);
        $tail = \array_shift($parts);

        if ('' === $path) {
            throw new RuntimeException(\sprintf(
                'Malformed line in .artifact-gate-placement: "%s". A line with no path classifies nothing '
                . 'and would register under the empty string instead of failing at parse time.',
                $line,
            ));
        }

        if (null === $placement || '' === $placement) {
            throw new RuntimeException(\sprintf(
                'Malformed line in .artifact-gate-placement: "%s". Expected `<path> :: home`, '
                . '`<path> :: mirrored :: <subject directory>` or `<path> :: undecided :: <reason>`.',
                $line,
            ));
        }

        // A surplus field is refused rather than dropped: a line carrying more than its grammar allows was
        // written against a different grammar, and reading it as valid is how a registry starts meaning
        // something other than what its header says.
        if ([] !== $parts) {
            throw new RuntimeException(\sprintf(
                'Line for "%s" carries %d field(s) beyond its grammar.',
                $path,
                \count($parts),
            ));
        }

        return [$path, self::placement($path, $placement, $tail)];
    }

    /**
     * @return Placement
     */
    private static function placement(string $path, string $placement, ?string $tail): array
    {
        return match ($placement) {
            self::IN_HOME => self::inHome($path, $tail),
            self::MIRRORED => [
                'placement' => self::MIRRORED,
                'subject' => self::subject($path, $tail),
                'reason' => null,
            ],
            self::UNDECIDED => [
                'placement' => self::UNDECIDED,
                'subject' => null,
                'reason' => self::reason($path, $tail),
            ],
            default => throw new RuntimeException(\sprintf(
                'Unknown placement "%s" for "%s". Expected one of: %s, %s, %s.',
                $placement,
                $path,
                self::IN_HOME,
                self::MIRRORED,
                self::UNDECIDED,
            )),
        };
    }

    /**
     * @return Placement
     */
    private static function inHome(string $path, ?string $tail): array
    {
        if (null !== $tail) {
            throw new RuntimeException(\sprintf(
                'Line for "%s" is classified `home` and carries a third field. The home needs no '
                . 'justification; only a placement away from it does.',
                $path,
            ));
        }

        return ['placement' => self::IN_HOME, 'subject' => null, 'reason' => null];
    }

    private static function subject(string $path, ?string $tail): string
    {
        if (null === $tail || '' === $tail) {
            throw new RuntimeException(\sprintf(
                'Line for "%s" is classified `mirrored` but names no subject. A mirror without the thing it '
                . 'mirrors is a word, not a placement.',
                $path,
            ));
        }

        $trimmed = \rtrim($tail, '/');

        foreach (self::SUBJECT_ROOTS as $root) {
            $bareRoot = \rtrim($root, '/');

            // A bare root is not a subject: `src` mirrors the whole of `tests/Unit`, which is not a
            // placement, and it is the one value that reaches the "unreachable" arm of mirrorOf(). Compared
            // against the TRIMMED tail and checked before the prefix match below — `str_starts_with` fails
            // on a bare root missing its own trailing slash (`"src"` does not start with `"src/"`) and would
            // otherwise fall through to the generic "under neither src/ nor tests/" refusal instead of this
            // one.
            if ($bareRoot === $trimmed) {
                throw new RuntimeException(\sprintf(
                    'Line for "%s" names "%s" as its subject. A whole source root is not a module; name the '
                    . 'directory the file mirrors.',
                    $path,
                    $tail,
                ));
            }

            if (!\str_starts_with($tail, $root)) {
                continue;
            }

            return $trimmed;
        }

        throw new RuntimeException(\sprintf(
            'Line for "%s" names subject "%s", which is under neither src/ nor tests/. Those are the two '
            . 'things tests/Unit mirrors; anything else has no mirror to sit on.',
            $path,
            $tail,
        ));
    }

    private static function reason(string $path, ?string $tail): string
    {
        if (null === $tail || '' === $tail) {
            throw new RuntimeException(\sprintf(
                'Line for "%s" is classified `undecided` but states no reason. An exemption with no reason '
                . 'is indistinguishable from an oversight, which is the thing the classification exists to '
                . 'prevent.',
                $path,
            ));
        }

        return $tail;
    }
}
