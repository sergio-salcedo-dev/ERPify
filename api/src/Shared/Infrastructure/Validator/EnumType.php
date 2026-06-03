<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Validator;

use Attribute;
use BackedEnum;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Asserts that a value is a case of the given backed enum, optionally restricted
 * to a subset of cases. Behaves like any other property constraint and fires
 * during entity/DTO validation. Validates the hydrated enum instance (or null
 * when allowNull); it does not coerce raw scalars.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class EnumType extends Constraint
{
    public string $message = 'The value you selected is not a valid choice.';

    /**
     * @param class-string<BackedEnum> $enumClass
     * @param list<BackedEnum>         $cases     optional subset; empty means the whole enum is allowed
     */
    #[HasNamedArguments]
    public function __construct(
        public string $enumClass,
        public bool $allowNull = false,
        public array $cases = [],
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
