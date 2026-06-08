<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Http\Search;

use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Domain\Search\FilterOperator;
use LogicException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One entry of the generic `filters[N][field|operator|value]` wire contract, mapped by
 * `#[MapQueryString]` as a nested DTO inside {@see SearchQuery::$filters}.
 *
 * Shape validation lives HERE, at mapping time (operator token, value/operator coherence,
 * caps) and surfaces as 400 `validation-failed` with `violations[]`. Field/operator
 * semantics (allow-list membership) are the applier's job downstream — never validated in
 * controllers or use cases.
 */
final readonly class FilterQuery
{
    public const int MAX_IN_VALUES = 100;

    // Mirrors the searchable columns' VARCHAR(255) bound: a longer value could never match,
    // so it is rejected at mapping time instead of travelling to the database.
    private const int MAX_VALUE_LENGTH = 255;

    /**
     * Pre-validation boundary type: the query string can encode anything array-shaped, so the
     * honest type here is `string|array<mixed>`; `list<string>` only holds AFTER the callback
     * below has validated the shape (D5).
     *
     * @param string|array<mixed> $value
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $field = '',
        #[Assert\NotNull]
        public ?FilterOperator $operator = null,
        public array|string $value = '',
    ) {
    }

    #[Assert\Callback]
    public function validateValueShape(ExecutionContextInterface $context): void
    {
        match ($this->operator) {
            FilterOperator::Eq,
            FilterOperator::Contains,
            FilterOperator::Gt,
            FilterOperator::Gte,
            FilterOperator::Lt,
            FilterOperator::Lte => $this->validateScalarValue($context),
            FilterOperator::In => $this->validateListValue($context),
            null => null, // NotNull on $operator already reports it.
        };
    }

    public function toFilter(): Filter
    {
        return match ($this->operator) {
            FilterOperator::Eq => Filter::eq($this->field, $this->scalarValue()),
            FilterOperator::Contains => Filter::contains($this->field, $this->scalarValue()),
            FilterOperator::Gt => Filter::gt($this->field, $this->scalarValue()),
            FilterOperator::Gte => Filter::gte($this->field, $this->scalarValue()),
            FilterOperator::Lt => Filter::lt($this->field, $this->scalarValue()),
            FilterOperator::Lte => Filter::lte($this->field, $this->scalarValue()),
            FilterOperator::In => Filter::in($this->field, $this->listValue()),
            null => throw new LogicException('Cannot convert a FilterQuery without operator to a Filter.'),
        };
    }

    private function validateScalarValue(ExecutionContextInterface $context): void
    {
        if (!\is_string($this->value)) {
            $context->buildViolation('This operator requires a single string value.')
                ->atPath('value')
                ->addViolation()
            ;

            return;
        }

        if ('' === \trim($this->value)) {
            $context->buildViolation('This value should not be blank.')
                ->atPath('value')
                ->addViolation()
            ;

            return;
        }

        if (\mb_strlen($this->value) > self::MAX_VALUE_LENGTH) {
            $context->buildViolation('This value is too long.')
                ->atPath('value')
                ->addViolation()
            ;
        }
    }

    private function validateListValue(ExecutionContextInterface $context): void
    {
        if (!\is_array($this->value) || !\array_is_list($this->value)) {
            $context->buildViolation('The in operator requires a non-empty list of values.')
                ->atPath('value')
                ->addViolation()
            ;

            return;
        }

        if ([] === $this->value) {
            $context->buildViolation('The in operator requires a non-empty list of values.')
                ->atPath('value')
                ->addViolation()
            ;

            return;
        }

        if (\count($this->value) > self::MAX_IN_VALUES) {
            $context->buildViolation('Too many values for the in operator.')
                ->atPath('value')
                ->addViolation()
            ;

            return;
        }

        foreach ($this->value as $index => $item) {
            $this->validateListItem($context, $index, $item);
        }
    }

    private function validateListItem(ExecutionContextInterface $context, int $index, mixed $item): void
    {
        $path = \sprintf('value[%d]', $index);

        if (!\is_string($item)) {
            $context->buildViolation('Each in value must be a string.')->atPath($path)->addViolation();

            return;
        }

        if ('' === \trim($item)) {
            $context->buildViolation('This value should not be blank.')->atPath($path)->addViolation();

            return;
        }

        if (\mb_strlen($item) > self::MAX_VALUE_LENGTH) {
            $context->buildViolation('This value is too long.')->atPath($path)->addViolation();
        }
    }

    private function scalarValue(): string
    {
        if (!\is_string($this->value)) {
            throw new LogicException('Scalar operators carry a string value once validated.');
        }

        return $this->value;
    }

    /**
     * @return non-empty-list<string>
     */
    private function listValue(): array
    {
        if (!\is_array($this->value) || [] === $this->value) {
            throw new LogicException('The in operator carries a non-empty list once validated.');
        }

        return \array_values(\array_map(
            static fn (mixed $item): string => \is_string($item)
                ? $item
                : throw new LogicException('The in operator carries string values once validated.'),
            $this->value,
        ));
    }
}
