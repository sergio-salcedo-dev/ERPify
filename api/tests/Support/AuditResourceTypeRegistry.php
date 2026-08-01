<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * Resolution engine behind the person-resource erasure gate: it reads the declared classification of every
 * audit `resource_type`, derives the types the source tree actually names, and decides whether a type
 * declared as person-denoting is really erased by the file that claims to erase it.
 *
 * Split from the gate test so the rules are exercisable independently of the assertions, the way
 * {@see PersonReferences}, {@see PersistentTransportPolicy}, {@see AllowlistFile} and {@see ApiSourceFiles}
 * already are — and so a fixture tree can drive them against forms the real tree does not contain (a witness
 * that never asserts the row disappears, an owner that holds the anonymiser but never calls it) without a
 * dirty line ever existing in the committed registry. A control whose red cannot be provoked is not a
 * control.
 *
 * Read only. It never writes the registry, and no target regenerates it: a control that rewrites what it
 * checks reads green by construction.
 *
 * @internal test support
 */
final class AuditResourceTypeRegistry
{
    public const string NON_PERSON = 'non-person';

    private const string ANONYMISER = 'AuditResourceAnonymiser';

    public function __construct(
        private readonly string $apiRoot,
        private readonly string $sourceRoot,
        private readonly string $registryPath,
    ) {
    }

    public static function fromGateLocation(string $gateDirectory): self
    {
        $apiRoot = \dirname($gateDirectory, 4);

        return new self($apiRoot, $apiRoot . '/src', $apiRoot . '/.audit-resource-types');
    }

    /**
     * The committed classification. A `person` line resolves to its two declared paths — the file that
     * erases the type, and the witness that proves the erasure reaches a row of it.
     *
     * @return array<string, PersonResourceDeclaration|null> type => declaration, or null when non-person
     */
    public function classification(): array
    {
        $registry = [];

        foreach (AllowlistFile::entries($this->registryPath) as $line) {
            $parts = \array_map(trim(...), \explode('=>', $line, 2));

            if (2 !== \count($parts)) {
                throw new RuntimeException(
                    'Malformed registry line (expected `Type => classification`): ' . $line,
                );
            }

            [$type, $value] = $parts;

            // Last-wins would let a duplicate line silently downgrade a person type to non-person, and the
            // wiring check skips non-person types — so the shadowed line would take the erasure with it.
            if (\array_key_exists($type, $registry)) {
                throw new RuntimeException(\sprintf(
                    'Duplicate registry line for "%s": the later classification silently shadows the earlier.',
                    $type,
                ));
            }

            $registry[$type] = $this->classificationOf($type, $value);
        }

        return $registry;
    }

    /**
     * Every type the source tree names as an audit resource: written at an `AuditResource::of()` call, held
     * in a same-class constant that call resolves, or declared as a route's `_audit_resource_type` default.
     *
     * @return list<string>
     */
    public function resourceTypesInSource(): array
    {
        $types = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) \file_get_contents($this->apiRoot . '/' . $file);

            \preg_match_all("/AuditResource::of\\(\\s*'([^']+)'/", $source, $constructed);
            \preg_match_all("/'_audit_resource_type'\\s*=>\\s*'([^']+)'/", $source, $routed);

            $types = [...$types, ...$constructed[1], ...$routed[1], ...$this->typesHeldInConstants($source)];
        }

        $types = \array_values(\array_unique($types));
        \sort($types);

        return $types;
    }

    /**
     * Classified types nothing writes any more — a graveyard entry, and the registry is meant to be a live
     * inventory.
     *
     * `non-person` only, and the exclusion is the load-bearing part: the two classifications carry different
     * risks. For a `person` line the risk is not an entry nobody uses, it is an obligation nobody executes,
     * and reading the type literal out of `src` cannot see that — a person type's only literal is the
     * constant its own declared owner holds, so the check would be satisfied by the very declaration it
     * verifies. The witness is what establishes a person line's liveness instead.
     *
     * @return list<string>
     */
    public function staleNonPersonTypes(): array
    {
        $stale = [];

        foreach ($this->classification() as $type => $declaration) {
            if (null === $declaration && [] === $this->sourceFilesCarrying($type)) {
                $stale[] = $type;
            }
        }

        return $stale;
    }

    /**
     * Files under the source root holding the quoted type literal, relative to `api/` so they compare against
     * the paths the registry declares.
     *
     * @return list<string>
     */
    public function sourceFilesCarrying(string $type): array
    {
        $carriers = [];

        foreach ($this->sourceFiles() as $file) {
            if (\str_contains((string) \file_get_contents($this->apiRoot . '/' . $file), $this->literal($type))) {
                $carriers[] = $file;
            }
        }

        \sort($carriers);

        return $carriers;
    }

    /**
     * Why the declared owner of `$type` fails to erase it, or `null` when it does. The owner must hold an
     * {@see self::ANONYMISER} property, call `anonymise()` on it, and carry the type literal — matched over
     * comment-stripped source, so a docblock naming the collaborator cannot stand in for the call.
     */
    public function erasureDefectIn(string $type, string $erasurePath): ?string
    {
        $unreadable = $this->pathDefectIn($erasurePath, 'src/', 'php');

        if (null !== $unreadable) {
            return \sprintf('the erasure owner declared for "%s" is unusable: %s', $type, $unreadable);
        }

        $code = $this->codeWithoutComments((string) \file_get_contents($this->apiRoot . '/' . $erasurePath));

        if (1 !== \preg_match(\sprintf('/%s\s+\$(\w+)/', self::ANONYMISER), $code, $property)) {
            return \sprintf('%s holds no %s property, so it cannot erase "%s"', $erasurePath, self::ANONYMISER, $type);
        }

        if (!\str_contains($code, \sprintf('$this->%s->anonymise(', $property[1]))) {
            return \sprintf(
                '%s holds a %s but never calls anonymise() on it, so "%s" is declared as erased while nothing '
                . 'erases it',
                $erasurePath,
                self::ANONYMISER,
                $type,
            );
        }

        if (!\str_contains($code, $this->literal($type))) {
            return \sprintf('%s does not carry the "%s" literal, so it cannot be what erases it', $erasurePath, $type);
        }

        return null;
    }

    /**
     * Why the declared witness of `$type` fails to establish that its erasure reaches a row of it, or `null`
     * when it does.
     *
     * The witness answers a question the erasure owner cannot answer about itself: that the declared erasure
     * path really reaches a row of a type the witness itself seeded. Being a DIFFERENT artefact is the whole
     * mechanism — a check a declaration can satisfy on its own carries no information — and that disjointness
     * is structural rather than compared: an owner is a `.php` under `src/` and a witness a `.feature` under
     * `features/`, so no path can be accepted as both. Relaxing either prefix takes the guarantee with it,
     * which is why a `src/` path being refused as a witness is pinned as its own case.
     */
    public function witnessDefectIn(string $type, string $witnessPath): ?string
    {
        $unreadable = $this->pathDefectIn($witnessPath, 'features/', 'feature');

        if (null !== $unreadable) {
            return \sprintf('the witness declared for "%s" is unusable: %s', $type, $unreadable);
        }

        $lines = $this->linesOf($witnessPath);

        if (!$this->writesType($lines, $type)) {
            return \sprintf('%s never writes a row of "%s", so nothing it asserts is about that type', $witnessPath, $type);
        }

        if (!$this->assertsTypeIsGone($lines, $type)) {
            return \sprintf(
                '%s writes a row of "%s" but never asserts that no row of it survives, so it witnesses the '
                . 'write and not the erasure',
                $witnessPath,
                $type,
            );
        }

        return null;
    }

    /**
     * A read of the type that is immediately answered with a zero count. Immediacy is what makes the check
     * mean something: a query and a count separated by other steps could be about different rows, and the
     * witness would then assert that some unrelated result set was empty.
     *
     * @param list<string> $lines
     */
    private function assertsTypeIsGone(array $lines, string $type): bool
    {
        foreach ($lines as $index => $line) {
            if (!\str_contains($line, $this->literal($type)) || 1 !== \preg_match('/\bSELECT\b/i', $line)) {
                continue;
            }

            if (1 === \preg_match('/\b0\s+(?:records|rows)\b/i', $this->stepAfter($lines, $index))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     */
    private function writesType(array $lines, string $type): bool
    {
        return \array_any($lines, fn (string $line): bool => \str_contains($line, $this->literal($type))
            && 1 === \preg_match('/\bINSERT\b/i', $line));
    }

    /**
     * The next line that carries a step, skipping blanks and Gherkin comments — a comment between the query
     * and its count is idiomatic in this suite and must not read as the absence of an assertion.
     *
     * @param list<string> $lines
     */
    private function stepAfter(array $lines, int $index): string
    {
        foreach (\array_slice($lines, $index + 1) as $line) {
            $trimmed = \trim($line);

            if ('' !== $trimmed && !\str_starts_with($trimmed, '#')) {
                return $trimmed;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function linesOf(string $path): array
    {
        return \preg_split('/\R/', (string) \file_get_contents($this->apiRoot . '/' . $path)) ?: [];
    }

    /**
     * Why a declared path cannot be read at all, or `null` when it can.
     *
     * `is_file()` rather than `file_exists()`: a DIRECTORY satisfies `file_exists()`, so declaring a bare
     * directory would silence every check downstream with nothing written at all.
     */
    private function pathDefectIn(string $path, string $prefix, string $extension): ?string
    {
        if (\str_contains($path, '..')) {
            return \sprintf('"%s" escapes the repository with ".."', $path);
        }

        if (!\str_starts_with($path, $prefix) || !\str_ends_with($path, '.' . $extension)) {
            return \sprintf('"%s" is not a .%s file under %s', $path, $extension, $prefix);
        }

        if (!\is_file($this->apiRoot . '/' . $path)) {
            return \sprintf('"%s" does not exist', $path);
        }

        return null;
    }

    /**
     * Resolves `AuditResource::of(self::SOME_TYPE, …)` back to the literal the constant holds, within the
     * same file. Both non-literal call sites in this codebase take that form — including the person type,
     * whose literal deliberately lives in the owning context rather than at the call.
     *
     * @return list<string>
     */
    private function typesHeldInConstants(string $source): array
    {
        \preg_match_all('/AuditResource::of\(\s*(?:self|static)::(\w+)/', $source, $references);

        $types = [];

        foreach ($references[1] as $constant) {
            $declaration = \sprintf("/const\\s+string\\s+%s\\s*=\\s*'([^']+)'/", \preg_quote($constant, '/'));

            if (1 === \preg_match($declaration, $source, $literal) && isset($literal[1])) {
                $types[] = $literal[1];
            }
        }

        return $types;
    }

    /**
     * Paths of every PHP file under the source root, relative to `api/`.
     *
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $prefix = \strlen($this->apiRoot) + 1;
        $files = [];

        foreach (ApiSourceFiles::phpFiles($this->sourceRoot) as $file) {
            $files[] = \substr($file->getPathname(), $prefix);
        }

        return $files;
    }

    /**
     * Anything unrecognised is rejected rather than read as non-person: a capitalisation slip must not
     * quietly become "nobody has to erase this", which is the failure mode the registry exists against. A
     * `person` line without its witness is not a spelling either — the witness is what makes the erasure
     * claim falsifiable, so a line that omits it declares an obligation nothing checks.
     */
    private function classificationOf(string $type, string $value): ?PersonResourceDeclaration
    {
        if (self::NON_PERSON === $value) {
            return null;
        }

        if (1 !== \preg_match('/^person\s*::\s*(\S[^:]*?)\s*::\s*(\S.*)$/', $value, $person)) {
            throw new RuntimeException(\sprintf(
                'Unrecognised classification for "%s": "%s". Write exactly `%s`, or `person :: <path of the '
                . 'erasure use case> :: <path of the witness that proves it reaches a row of the type>`.',
                $type,
                $value,
                self::NON_PERSON,
            ));
        }

        return new PersonResourceDeclaration(\trim($person[1]), \trim($person[2]));
    }

    /**
     * Strips comments so the wiring check reads code only — a docblock that names the anonymiser describes
     * an intention, and an intention must not be able to stand in for the call.
     */
    private function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (\token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function literal(string $type): string
    {
        return \sprintf("'%s'", $type);
    }
}
