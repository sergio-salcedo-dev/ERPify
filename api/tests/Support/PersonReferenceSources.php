<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Error;
use ReflectionClass;
use RuntimeException;

/**
 * Resolution engine behind the source-coverage gate: it derives, from the source tree, which person-reference
 * axis each {@see PersonReferenceSource} declares it covers.
 *
 * It is the bridge that lets the detective control be enumerated against `api/.person-reference-policy`
 * instead of against a list somebody maintains. The alternative bridge — matching a source's class name
 * against a registry key by similarity, `DbalMembershipPersonReferences` ↔ `Membership::$userId` — is the
 * fragile enumeration the gate exists to abolish: it goes green on a rename and cannot see a source that
 * covers a column of a differently-named entity at all.
 *
 * Split from the gate test for the reason {@see PersonReferences} is: the rules stay exercisable over a
 * fixture tree, so both directions of the comparison can be shown to go red without a broken source ever
 * existing in `src`.
 *
 * The axis is read by constructing each implementation WITHOUT its constructor and asking it. That is what
 * makes the read possible with no database and no container, and it is why the contract states that
 * `axis()` must be a constant fact of the class: a source deriving it from injected state fails here loudly,
 * naming itself, rather than being skipped.
 *
 * Read only. It never writes the registry and no target regenerates it.
 *
 * @internal test support
 */
final readonly class PersonReferenceSources
{
    public function __construct(
        private string $sourceRoot,
        private string $namespacePrefix,
    ) {
    }

    public static function fromGateLocation(string $gateDirectory): self
    {
        $apiRoot = \dirname($gateDirectory, 4);

        return new self($apiRoot . '/src', 'Erpify\\');
    }

    /**
     * Every axis some source claims, with the sources claiming it.
     *
     * The value is a LIST rather than a single class so the gate can see two sources covering one axis. That
     * pairing is not harmless: the reconciler keys its verdict by the axis, so the second source's findings
     * would replace the first's and one whole column would go unreported while both classes look wired.
     *
     * @return array<string, list<string>> axis key => FQCNs declaring it
     */
    public function declaredAxes(): array
    {
        $axes = [];

        foreach ($this->sourceClasses() as $reflection) {
            $axes[$this->axisKeyOf($reflection)][] = $reflection->getName();
        }

        // Determinism: the directory walk has no guaranteed order across machines, and a failure message
        // that reorders between runs is the cheapest route to a red CI that reproduces nowhere.
        \ksort($axes);

        foreach ($axes as $key => $declaring) {
            \sort($declaring);
            $axes[$key] = $declaring;
        }

        return $axes;
    }

    /**
     * @return iterable<ReflectionClass<object>>
     */
    private function sourceClasses(): iterable
    {
        foreach (ApiSourceFiles::phpFiles($this->sourceRoot) as $file) {
            $relative = \substr($file->getPathname(), \strlen($this->sourceRoot) + 1);
            $fqcn = $this->namespacePrefix . \str_replace('/', '\\', \substr($relative, 0, -4));

            if (!\class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);

            // Abstract carriers and enums back no service, so neither can be collected into the reconciler;
            // asking them for an axis would report a class the container never builds.
            if ($reflection->isAbstract()) {
                continue;
            }

            if ($reflection->isEnum()) {
                continue;
            }

            if ($reflection->implementsInterface(PersonReferenceSource::class)) {
                yield $reflection;
            }
        }
    }

    /**
     * The `Error` is the whole point of catching here: reading a promoted property that the skipped
     * constructor never assigned raises one, and it names the property but neither the class nor what the
     * contract expected — a message nobody can act on, for the one mistake this shape invites. `Error`
     * rather than `Throwable` because only an unassigned-state access is being interpreted; anything else
     * an implementation throws is not this rule's to explain, and `Error::getCode()` is an `int` while
     * `Throwable::getCode()` is not.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function axisKeyOf(ReflectionClass $reflection): string
    {
        $source = $reflection->newInstanceWithoutConstructor();

        if (!$source instanceof PersonReferenceSource) {
            throw new RuntimeException(\sprintf(
                '%s implements the person-reference source contract but did not reflect as one.',
                $reflection->getName(),
            ));
        }

        try {
            return $source->axis()->key();
        } catch (Error $error) {
            throw new RuntimeException(\sprintf(
                'The axis of %s cannot be read without constructing it (%s). It has to be a constant fact of '
                . 'the class: this gate reads it from the source tree, with no container and no database, so '
                . 'an axis derived from injected state leaves the source unenumerable against the registry.',
                $reflection->getName(),
                $error->getMessage(),
            ), $error->getCode(), previous: $error);
        }
    }
}
