<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool\TypeHint;

/**
 * Orchestrates the query-string type-hint resolution chain: the first resolver that claims the
 * value/type pair wins, otherwise the raw value passes through unchanged.
 *
 * Order is semantic — enums also pass `class_exists()`, so {@see EnumValueResolver} MUST precede
 * {@see ValueObjectResolver}.
 */
final readonly class TypeHintValueResolver
{
    /** @param list<ValueResolverInterface> $resolvers */
    public function __construct(
        private array $resolvers = [
            new NullValueResolver(),
            new EnumValueResolver(),
            new ValueObjectResolver(),
            new DateValueResolver(),
        ],
    ) {
    }

    public function resolve(mixed $value, ?string $type): mixed
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($value, $type)) {
                return $resolver->resolve($value, $type);
            }
        }

        return $value;
    }
}
