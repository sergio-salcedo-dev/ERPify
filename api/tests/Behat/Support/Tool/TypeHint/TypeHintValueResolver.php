<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool\TypeHint;

/**
 * Orchestrates the query-string type-hint resolution chain: the first resolver that claims the
 * value/type pair wins, otherwise the raw value passes through unchanged.
 *
 * A backed enum's `->value` IS its wire contract, so an enum-typed hint needs no dedicated resolver:
 * it reaches {@see ValueObjectResolver}, which cannot instantiate an enum and falls back to the raw
 * wire value — which already equals the enum's identity.
 */
final readonly class TypeHintValueResolver
{
    /** @param list<ValueResolverInterface> $resolvers */
    public function __construct(
        private array $resolvers = [
            new NullValueResolver(),
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
