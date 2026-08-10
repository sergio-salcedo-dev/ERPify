<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Reads the Behat step vocabulary out of the tree: what the contexts declare, what the features write,
 * and which of the first each of the second resolves to.
 *
 * Textual rather than by booting Behat, like the gates it serves: `Behat\Config\*` is a prerelease API
 * this repo pins at `^4.0@alpha`, so introspecting it would couple the reading to a shape upstream may
 * still change.
 *
 * It refuses rather than approximates. A pattern it cannot reconstruct exactly — an attribute argument
 * that is not a concatenation of string literals and this file's own constants — throws instead of
 * registering whichever literals it could find, because a prefix of a step still looks like a
 * plausible step and would classify quietly under a name nothing uses.
 */
final class BehatVocabularyReader
{
    /**
     * Behat's own placeholder: a quoted string, or a bare run of non-space when the feature omits the
     * quotes.
     */
    private const string PLACEHOLDER_REGEX = '(?:"[^"]*"|\'[^\']*\'|\S+)';

    /** @var list<string>|null */
    private ?array $patterns = null;

    /** @var list<string>|null */
    private ?array $steps = null;

    public function __construct(
        private readonly string $supportDirectory,
        private readonly string $featuresDirectory,
    ) {
    }

    /**
     * Every step pattern any context declares, as Behat reads it out of the attribute.
     *
     * @return list<string>
     */
    public function declaredPatterns(): array
    {
        if (null !== $this->patterns) {
            return $this->patterns;
        }

        $patterns = [];

        foreach ($this->filesUnder($this->supportDirectory, 'php') as $path) {
            $source = (string) \file_get_contents($path);
            $constants = $this->stringConstantsOf($source);

            // The argument, whole: a pattern too long for one line is written as a concatenation, and
            // reading only its first literal would register a prefix as if it were the step.
            \preg_match_all('/#\[(?:Given|When|Then)\(\s*(.*?)\s*,?\s*\)\]/s', $source, $matches);

            foreach ($matches[1] as $argument) {
                $patterns[] = $this->concatenatedLiteral(\strtr($argument, $constants), $path);
            }
        }

        $patterns = \array_values(\array_unique($patterns));
        \sort($patterns);

        return $this->patterns = $patterns;
    }

    /**
     * Every step line written in a feature, with Scenario Outline placeholders stood in for: an
     * Examples value binds to the same pattern whatever it is, so substituting one keeps the row table
     * out of the matching.
     *
     * @return list<string>
     */
    public function featureSteps(): array
    {
        if (null !== $this->steps) {
            return $this->steps;
        }

        $steps = [];

        foreach ($this->filesUnder($this->featuresDirectory, 'feature') as $path) {
            $insideDocString = false;

            foreach (\explode("\n", (string) \file_get_contents($path)) as $line) {
                $line = \trim($line);

                if (\str_starts_with($line, '"""')) {
                    $insideDocString = !$insideDocString;

                    continue;
                }

                if ($insideDocString) {
                    continue;
                }

                if (1 !== \preg_match('/^(?:Given|When|Then|And|But|\*)\s+(.*)$/', $line, $found)) {
                    continue;
                }

                $steps[] = (string) \preg_replace('/<[^>]+>/', 'outline-value', $found[1]);
            }
        }

        return $this->steps = \array_values(\array_unique($steps));
    }

    /**
     * @return list<string> the feature step lines this pattern resolves
     */
    public function stepsMatching(string $pattern): array
    {
        $regex = $this->regexFor($pattern);

        return \array_values(\array_filter(
            $this->featureSteps(),
            static fn (string $step): bool => 1 === \preg_match($regex, $step),
        ));
    }

    /**
     * @return list<string> the feature step lines that resolve to no declared pattern at all
     */
    public function unresolvedSteps(): array
    {
        return $this->unresolvedStepsAmong($this->featureSteps());
    }

    /**
     * The same resolution, over any set of step lines — a refusal's suggested phrasing is one, and
     * checking it is what keeps a redirect pointing at a step that still exists.
     *
     * @param list<string> $steps
     *
     * @return list<string>
     */
    public function unresolvedStepsAmong(array $steps): array
    {
        $regexes = \array_map($this->regexFor(...), $this->declaredPatterns());
        $unresolved = [];

        foreach ($steps as $step) {
            foreach ($regexes as $regex) {
                if (1 === \preg_match($regex, $step)) {
                    continue 2;
                }
            }

            $unresolved[] = $step;
        }

        return \array_values(\array_unique($unresolved));
    }

    /**
     * A pattern already written as a regex is Behat's own escape hatch and is used verbatim. Otherwise
     * the turnip syntax is translated: `(x)` is an optional literal, `:name` a placeholder, the rest
     * literal text.
     */
    private function regexFor(string $pattern): string
    {
        if (\str_starts_with($pattern, '/')) {
            return $pattern;
        }

        $work = (string) \preg_replace('/\(([a-z]+)\)/', '@@O@@$1@@C@@', $pattern);
        $work = (string) \preg_replace('/:\w+/', '@@P@@', $work);
        $work = \preg_quote($work, '#');

        $work = \str_replace(['@@O@@', '@@C@@', '@@P@@'], ['(?:', ')?', self::PLACEHOLDER_REGEX], $work);

        return '#^' . $work . '$#u';
    }

    /**
     * The file's own single-literal string constants, as `self::NAME` => the quoted literal it holds.
     * A pattern long enough to be worth a constant is exactly the kind that must still be readable.
     *
     * @return array<string, string>
     */
    private function stringConstantsOf(string $source): array
    {
        \preg_match_all(
            "/const\\s+(?:\\w+\\s+)?(\\w+)\\s*=\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*;/",
            $source,
            $found,
            PREG_SET_ORDER,
        );

        $constants = [];

        foreach ($found as $constant) {
            $constants['self::' . $constant[1]] = \sprintf("'%s'", $constant[2]);
        }

        return $constants;
    }

    private function concatenatedLiteral(string $argument, string $path): string
    {
        \preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $argument, $literals);

        $residue = \trim((string) \preg_replace(["/'(?:[^'\\\\]|\\\\.)*'/", '/[\s.]+/'], '', $argument));

        if ('' !== $residue) {
            throw new RuntimeException(\sprintf(
                'A step attribute in %s is not a concatenation of string literals (leftover: %s). The '
                . 'vocabulary can only carry a pattern that can be reconstructed exactly.',
                $path,
                $residue,
            ));
        }

        $pattern = '';

        foreach ($literals[1] as $literal) {
            $pattern .= \str_replace(['\\\\', "\\'"], ['\\', "'"], $literal);
        }

        return $pattern;
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $directory, string $extension): array
    {
        // Depth-unbounded on purpose: a scan that stops at a fixed nesting level has the very blind
        // spot the gates over this reader exist to deny, one directory further down.
        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $paths = [];

        foreach ($tree as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $extension === $file->getExtension()) {
                $paths[] = $file->getPathname();
            }
        }

        \sort($paths);

        return $paths;
    }
}
