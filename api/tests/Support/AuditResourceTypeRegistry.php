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
 * already are — and so a fixture tree can drive them against forms the real tree does not contain (an owner
 * that holds the anonymiser but never calls it, a type nothing writes) without a dirty line ever existing in
 * the committed registry. A control whose red cannot be provoked is not a control.
 *
 * The second half of a `person` line — what proves the declared erasure reaches a row rather than merely
 * being written — is {@see AuditWitnessScenario}'s, reachable through {@see witness()}. Reading a registry
 * and reading an acceptance scenario are different jobs, and the declarations are the only thing they share.
 *
 * Read only. It never writes the registry, and no target regenerates it: a control that rewrites what it
 * checks reads green by construction.
 *
 * @internal test support
 */
final class AuditResourceTypeRegistry
{
    public const string NON_PERSON = 'non-person';

    /**
     * The collaborators that can erase the resource axis, each with the verb that does it. Two, because the
     * axis is reachable two ways and a check that knew only one would reject a correct owner: a use case may
     * hold {@see \Erpify\Shared\Audit\Application\AuditResourceAnonymiser} directly, or hold
     * {@see \Erpify\Shared\Audit\Application\AuditSubjectTrailErasure}, which orders that same pass behind
     * the row lock the two axes need. Adding a spelling here weakens the rule, so a new entry earns its place
     * only when the type it names really does clear `audit_log.resource_id`.
     *
     * @var array<string, string>
     */
    private const array ANONYMISERS = [
        'AuditResourceAnonymiser' => 'anonymise',
        'AuditSubjectTrailErasure' => 'completeForSubject',
    ];

    /**
     * One snapshot of the source corpus per instance, taken on first use.
     *
     * Every sweep below reads the same `api/src`, and the gates drive them from loops — one call per
     * classified type — so re-reading and re-tokenising 600-odd files is work repeated for an answer that
     * cannot have changed: no test writes under `src`, the fixture trees are committed directories, and the
     * fixers that do rewrite `src` are separate processes in the sweep that runs this gate. The snapshot is
     * per INSTANCE and never static, so it dies with the instance that took it and cannot become state one
     * test hands to the next.
     *
     * @var array<string, string>|null path relative to `api/` => file contents
     */
    private ?array $sources = null;

    /** @var array<string, string>|null `<ShortClassName>::<CONSTANT>` => the literal it holds */
    private ?array $constants = null;

    /** @var array<string, string>|null path relative to `api/` => that file's code with comments removed */
    private ?array $strippedSources = null;

    /** @var array<string, list<string>>|null path relative to `api/` => the audit resource types it names */
    private ?array $derivedTypes = null;

    public function __construct(
        private readonly string $apiRoot,
        private readonly string $sourceRoot,
        private readonly string $registryPath,
    ) {
    }

    public static function fromGateLocation(string $gateDirectory): self
    {
        $apiRoot = \dirname($gateDirectory, 3);

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
     * Every type the source tree names as an audit resource: written as a literal at an `AuditResource::of()`
     * call, held in a constant that call resolves — in the calling class or in another one it imports — or
     * declared as a route's `_audit_resource_type` default.
     *
     * @return list<string>
     */
    public function resourceTypesInSource(): array
    {
        $types = [];

        foreach ($this->derivedTypes() as $derived) {
            $types = [...$types, ...$derived];
        }

        $types = \array_values(\array_unique($types));
        \sort($types);

        return $types;
    }

    /**
     * Files the source tree derives `$type` FROM, relative to `api/`.
     *
     * The completeness check only needs the type to be derivable from somewhere; this answers where, which is
     * what lets a `person` line be pinned to a file rather than to the tree as a whole. A type nothing derives
     * yields an empty list — the same silence the completeness check is there to break.
     *
     * @return list<string>
     */
    public function filesDerivingType(string $type): array
    {
        $files = [];

        foreach ($this->derivedTypes() as $file => $types) {
            if (\in_array($type, $types, true)) {
                $files[] = $file;
            }
        }

        \sort($files);

        return $files;
    }

    /**
     * The audit resource types ONE file names, by any of the three forms the sweep understands.
     *
     * Takes the file's CODE, like its two siblings — {@see strippedSources()} is what removes the comments.
     * A docblock citing the call it used to make would otherwise satisfy "the declared owner builds this
     * type" with prose, which is the one thing this sweep must not accept as a writer.
     *
     * @param array<string, string> $constants
     *
     * @return list<string>
     */
    private function typesDerivedFrom(string $code, array $constants): array
    {
        \preg_match_all("/AuditResource::of\\(\\s*'([^']+)'/", $code, $constructed);
        \preg_match_all("/'_audit_resource_type'\\s*=>\\s*'([^']+)'/", $code, $routed);

        return [...$constructed[1], ...$routed[1], ...AuditResourceConstants::typesHeldIn($code, $constants)];
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
     * Files under the source root whose CODE holds the quoted type literal, relative to `api/` so they
     * compare against the paths the registry declares.
     *
     * Comment-stripped, like the wiring check beside it: a type named only in a docblock is described, not
     * used, so counting it as a carrier would keep a graveyard entry alive on prose alone — and would fire
     * the tripwire below on a file that merely mentions the type in passing.
     *
     * @return list<string>
     */
    public function sourceFilesCarrying(string $type): array
    {
        $carriers = [];

        foreach ($this->strippedSources() as $file => $code) {
            if (\str_contains($code, $this->literal($type))) {
                $carriers[] = $file;
            }
        }

        \sort($carriers);

        return $carriers;
    }

    /**
     * Why the declared owner of `$type` fails to erase it, or `null` when it does. The owner must hold an
     * {@see self::ANONYMISERS} property, call its erasing verb on it, and carry the type literal — matched over
     * comment-stripped source, so a docblock naming the collaborator cannot stand in for the call.
     */
    public function erasureDefectIn(string $type, string $erasurePath): ?string
    {
        $unreadable = DeclaredPath::defectIn($this->apiRoot, $erasurePath, 'src/', 'php');

        if (null !== $unreadable) {
            return \sprintf('the erasure owner declared for "%s" is unusable: %s', $type, $unreadable);
        }

        $code = $this->codeWithoutComments((string) \file_get_contents($this->apiRoot . '/' . $erasurePath));

        /** @var list<array{property: string, verb: string}> $held */
        $held = [];

        foreach (self::ANONYMISERS as $anonymiser => $verb) {
            \preg_match_all(\sprintf('/%s\s+(?:\.\.\.)?\$(\w+)/', $anonymiser), $code, $matches);

            foreach ($matches[1] as $collaborator) {
                $held[] = ['property' => $collaborator, 'verb' => $verb];
            }
        }

        if ([] === $held) {
            return \sprintf(
                '%s holds no %s property, so it cannot erase "%s"',
                $erasurePath,
                \implode(' or ', \array_keys(self::ANONYMISERS)),
                $type,
            );
        }

        // Every candidate, and `\s*\??` on the call: the sibling reference gate paid for both — one property
        // matched first would hide the collaborator that does the work, and a correct owner would be rejected
        // for putting the call on the next line or writing it null-safe.
        if (
            !\array_any($held, static fn (array $candidate): bool => 1 === \preg_match(
                \sprintf('/\$this->%s\s*\??->%s\(/', \preg_quote($candidate['property'], '/'), $candidate['verb']),
                $code,
            ))
        ) {
            return \sprintf(
                '%s holds a %s but never calls %s() on it, so "%s" is declared as erased while nothing '
                . 'erases it',
                $erasurePath,
                \implode(' or ', \array_keys(self::ANONYMISERS)),
                \implode('()/', \array_values(self::ANONYMISERS)),
                $type,
            );
        }

        if (!\str_contains($code, $this->literal($type))) {
            return \sprintf('%s does not carry the "%s" literal, so it cannot be what erases it', $erasurePath, $type);
        }

        return null;
    }

    /**
     * The witness reader, with the api root already resolved — the collaborator that answers the one
     * question a `person` declaration cannot answer about itself.
     */
    public function witness(): AuditWitnessScenario
    {
        return new AuditWitnessScenario($this->apiRoot);
    }

    /**
     * Contents of every file under the source root, keyed by its path relative to `api/`.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        return $this->sources ??= $this->readSources();
    }

    /**
     * @return array<string, string>
     */
    private function readSources(): array
    {
        $sources = [];

        foreach ($this->sourceFiles() as $file) {
            $sources[$file] = (string) \file_get_contents($this->apiRoot . '/' . $file);
        }

        return $sources;
    }

    /**
     * @return array<string, string>
     */
    private function constants(): array
    {
        return $this->constants ??= AuditResourceConstants::literalsIn($this->sources());
    }

    /**
     * The corpus with comments stripped — what every derivation below reads, so an intention written in a
     * docblock can never stand in for the code that would carry it.
     *
     * @return array<string, string>
     */
    private function strippedSources(): array
    {
        return $this->strippedSources ??= \array_map($this->codeWithoutComments(...), $this->sources());
    }

    /**
     * The audit resource types each file names, keyed by its path relative to `api/`.
     *
     * @return array<string, list<string>>
     */
    private function derivedTypes(): array
    {
        return $this->derivedTypes ??= $this->deriveTypes();
    }

    /**
     * @return array<string, list<string>>
     */
    private function deriveTypes(): array
    {
        $constants = $this->constants();

        return \array_map(
            fn (string $code): array => $this->typesDerivedFrom($code, $constants),
            $this->strippedSources(),
        );
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
        return PhpSource::withoutComments($source);
    }

    private function literal(string $type): string
    {
        return \sprintf("'%s'", $type);
    }
}
