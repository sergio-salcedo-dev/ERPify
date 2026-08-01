<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Resolution engine behind the person-reference gate: it derives, from the source tree, every persisted
 * identifier column an entity declares; reads the classification each one carries in
 * `api/.person-reference-policy`; reads the owners declared at the properties themselves; and decides
 * whether a declared owner actually erases what it claims to.
 *
 * Split from the gate test so the rules are exercisable independently of the assertions, the way
 * {@see PersistentTransportPolicy}, {@see AllowlistFile} and {@see ApiSourceFiles} already are — and so a
 * fixture tree can drive the derivation against forms the real tree does not contain (an unclassified
 * column, a stale entry, an owner that holds the collaborator but never calls it) without a dirty line
 * ever existing in the committed registry.
 *
 * Read only. It never writes the registry, and no target regenerates it: a control that rewrites what it
 * checks reads green by construction.
 *
 * @internal test support
 */
final class PersonReferences
{
    public const string NON_PERSON = 'non-person';

    /**
     * Method-name prefixes that count as erasing. The wiring check asks whether the declared owner CALLS one
     * of these on a collaborator that speaks the entity holding the reference — a property of the right type
     * with no call is an intention, and the whole point of the check is that an intention does not erase
     * anybody.
     */
    private const string ERASURE_VERBS = 'delete|remove|purge|erase|anonymise';

    /** @var list<string>|null */
    private ?array $universe = null;

    /** @var array<string, string>|null */
    private ?array $declaredOwners = null;

    public function __construct(
        private readonly string $apiRoot,
        private readonly string $sourceRoot,
        private readonly string $namespacePrefix,
        private readonly string $registryPath,
    ) {
    }

    public static function fromGateLocation(string $gateDirectory): self
    {
        $apiRoot = \dirname($gateDirectory, 4);

        return new self($apiRoot, $apiRoot . '/src', 'Erpify\\', $apiRoot . '/.person-reference-policy');
    }

    public function registryPath(): string
    {
        return $this->registryPath;
    }

    /**
     * Every persisted identifier column in the tree, as `<Fqcn>::$<property>`.
     *
     * The key is the property, not the entity: the lesson the persistent-transport registry paid for is that
     * a key coarser than the fact it classifies gives a green light to whatever the key averages over. The
     * universe is EVERY `Types::GUID` column of a concrete entity, with no name filter — `$actorId`,
     * `$createdBy` and `$customerId` are person references that no `*user*_id` heuristic would ever see.
     *
     * @return list<string>
     */
    public function universe(): array
    {
        $this->scan();

        return $this->universe ?? [];
    }

    /**
     * The owners declared at the properties themselves, over EVERY class in the tree rather than only the
     * entities — an attribute on something that is not a persisted column has to surface as a failure, not
     * fall silently outside the sweep, or the author has declared an owner the registry will never carry.
     *
     * @return array<string, string> `<Fqcn>::$<property>` => path relative to api/
     */
    public function declaredOwners(): array
    {
        $this->scan();

        return $this->declaredOwners ?? [];
    }

    /**
     * The committed classification.
     *
     * @return array<string, string|null> `<Fqcn>::$<property>` => owner path, or null when non-person
     */
    public function classification(): array
    {
        $registry = [];

        foreach (AllowlistFile::entries($this->registryPath) as $line) {
            $parts = \array_map(trim(...), \explode('=>', $line, 2));

            if (2 !== \count($parts)) {
                throw new RuntimeException(
                    'Malformed registry line (expected `Fqcn::$property => classification`): ' . $line,
                );
            }

            [$key, $value] = $parts;

            // Last-wins would let a duplicate line silently downgrade a person reference to non-person, and
            // the wiring check skips non-person lines — so the shadowed line would take the erasure with it.
            if (\array_key_exists($key, $registry)) {
                throw new RuntimeException(\sprintf(
                    'Duplicate registry line for "%s": the later classification silently shadows the earlier.',
                    $key,
                ));
            }

            $registry[$key] = $this->classificationOf($key, $value);
        }

        return $registry;
    }

    /**
     * Why the declared owner of `$key` fails to erase it, or `null` when it does.
     *
     * The rule is the persistent triple check of the sibling resource gate, generalised: the owner must hold a
     * collaborator that speaks the entity declaring the reference AND call a deletion on it, matched over
     * comment-stripped source so a docblock naming the repository cannot stand in for the call.
     */
    public function erasureDefectIn(string $key, string $ownerPath): ?string
    {
        if (\str_contains($ownerPath, '..')) {
            return \sprintf('the declared owner "%s" escapes the repository with ".."', $ownerPath);
        }

        if (!\str_starts_with($ownerPath, 'src/') || !\str_ends_with($ownerPath, '.php')) {
            return \sprintf('the declared owner "%s" is not a PHP file under src/', $ownerPath);
        }

        // is_file() rather than file_exists(): a DIRECTORY satisfies file_exists(), so `person :: src` would
        // silence the check with nothing written at all.
        if (!\is_file($this->apiRoot . '/' . $ownerPath)) {
            return \sprintf('the declared owner "%s" does not exist', $ownerPath);
        }

        $entity = $this->entityOf($key);
        $code = $this->codeWithoutComments((string) \file_get_contents($this->apiRoot . '/' . $ownerPath));

        $matches = [];
        \preg_match_all(\sprintf('/(?:\w*%s\w*)\s+\$(\w+)/', \preg_quote($entity, '/')), $code, $matches);
        $collaborators = $matches[1] ?? [];

        if ([] === $collaborators) {
            return \sprintf(
                '%s holds no collaborator naming "%s", so it cannot be what erases that reference',
                $ownerPath,
                $entity,
            );
        }

        foreach ($collaborators as $collaborator) {
            $call = \sprintf('/\$this->%s->(?:%s)\w*\(/', \preg_quote($collaborator, '/'), self::ERASURE_VERBS);

            if (1 === \preg_match($call, $code)) {
                return null;
            }
        }

        return \sprintf(
            '%s holds a "%s" collaborator but never calls a deletion (%s) on it, so the reference is declared '
            . 'erased while nothing erases it',
            $ownerPath,
            $entity,
            \str_replace('|', ', ', self::ERASURE_VERBS),
        );
    }

    /**
     * Short name of the entity declaring `$key` — the type whose row holds the reference, and therefore the
     * type its erasure has to speak.
     */
    public function entityOf(string $key): string
    {
        $fqcn = \substr($key, 0, (int) \strpos($key, '::'));

        return \substr($fqcn, (int) \strrpos($fqcn, '\\') + 1);
    }

    private function scan(): void
    {
        if (null !== $this->universe) {
            return;
        }

        $universe = [];
        $declaredOwners = [];

        foreach (ApiSourceFiles::phpFiles($this->sourceRoot) as $file) {
            $relative = \substr($file->getPathname(), \strlen($this->sourceRoot) + 1);
            $fqcn = $this->namespacePrefix . \str_replace('/', '\\', \substr($relative, 0, -4));

            if (!\class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isAbstract()) {
                continue;
            }

            $isEntity = [] !== $reflection->getAttributes(Entity::class);

            foreach ($reflection->getProperties() as $property) {
                $key = \sprintf('%s::$%s', $fqcn, $property->getName());

                foreach ($property->getAttributes(PersonSubjectReference::class) as $attribute) {
                    $declaredOwners[$key] = $attribute->newInstance()->erasedBy;
                }

                if ($isEntity && $this->isIdentifierColumn($property)) {
                    $universe[] = $key;
                }
            }
        }

        $universe = \array_values(\array_unique($universe));
        // Determinism: the directory walk has no guaranteed order across machines, and a failure message
        // that reorders between runs is the cheapest route to a red CI that reproduces nowhere.
        \sort($universe);
        \ksort($declaredOwners);

        $this->universe = $universe;
        $this->declaredOwners = $declaredOwners;
    }

    /**
     * A persisted `Types::GUID` column. Both declaration forms count — declared in the class body (the form
     * both seeds use) and promoted in the constructor — because `getProperties()` returns them alike and a
     * gate blind to either half would miss whichever the next entity happens to pick.
     */
    private function isIdentifierColumn(ReflectionProperty $property): bool
    {
        if ($property->isStatic()) {
            return false;
        }

        return \array_any(
            $property->getAttributes(Column::class),
            static fn (ReflectionAttribute $attribute): bool => Types::GUID === $attribute->newInstance()->type,
        );
    }

    /**
     * Anything unrecognised is rejected rather than read as non-person: a capitalisation slip must not
     * quietly become "nobody has to erase this", which is the failure mode the registry exists against.
     * `person` with no owner is not a state — an unowned person reference IS the violation, so it can never
     * be spelled as a passing line.
     */
    private function classificationOf(string $key, string $value): ?string
    {
        if (self::NON_PERSON === $value) {
            return null;
        }

        if (1 !== \preg_match('/^person\s*::\s*(\S.*)$/', $value, $person)) {
            throw new RuntimeException(\sprintf(
                'Unrecognised classification for "%s": "%s". Write exactly `%s`, or `person :: <path of the '
                . 'file that erases it>`.',
                $key,
                $value,
                self::NON_PERSON,
            ));
        }

        return \trim($person[1]);
    }

    /**
     * Strips comments so the wiring check reads code only — a docblock that names the repository describes an
     * intention, and an intention must not be able to stand in for the call.
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
}
