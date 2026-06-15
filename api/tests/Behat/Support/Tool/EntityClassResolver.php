<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/**
 * Resolves a Behat entity-step class reference to a concrete Doctrine-mapped FQCN.
 *
 * A fully-qualified name short-circuits; a configured namespace is honoured next; otherwise the name
 * is matched against the basenames of every mapped entity. A unique basename match wins, while
 * ambiguity (the same basename in two bounded contexts) or no match fails loudly listing the
 * candidates so the step author switches to a FQCN — the resolver never silently picks one.
 */
final readonly class EntityClassResolver
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return class-string<object>
     */
    public function resolve(string $name, ?string $namespace): string
    {
        if (\class_exists($name)) {
            return $name;
        }

        if (null !== $namespace) {
            $namespaced = $namespace . '\\' . $name;

            if (\class_exists($namespaced)) {
                return $namespaced;
            }
        }

        $candidates = $this->mappedClassesByBasename($name);

        if (\count($candidates) > 1) {
            throw new InvalidArgumentException(\sprintf(
                'Entity short name "%s" is ambiguous across mapped entities: %s. Use a FQCN.',
                $name,
                \implode(', ', $candidates),
            ));
        }

        return $candidates[0] ?? throw new InvalidArgumentException(\sprintf(
            'No mapped entity matches the short name "%s". Use a FQCN.',
            $name,
        ));
    }

    /**
     * @return list<class-string<object>>
     */
    private function mappedClassesByBasename(string $name): array
    {
        $matches = [];

        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $classMetadatum) {
            $fqcn = $classMetadatum->getName();

            if ($name === $this->classBasename($fqcn)) {
                $matches[] = $fqcn;
            }
        }

        return $matches;
    }

    private function classBasename(string $fqcn): string
    {
        $position = \strrpos($fqcn, '\\');

        return false === $position ? $fqcn : \substr($fqcn, $position + 1);
    }
}
