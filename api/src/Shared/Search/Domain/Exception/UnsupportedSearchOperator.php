<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Domain\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;
use Erpify\Shared\Search\Domain\FilterOperator;

/**
 * Thrown by the filter applier when a filter uses an operator the field's `FieldMapping`
 * does not allow (e.g. CONTAINS on a UUID column). Implements the
 * {@see InvalidSearchCriteria} marker so the Problem Details pipeline maps it to HTTP 400
 * with no extra wiring.
 *
 * `context` carries the operator's wire token (`FilterOperator->value`), never the enum
 * instance — a non-JsonSerializable object would be replaced by the factory's
 * `[unserializable]` sentinel in the extensions.
 */
final class UnsupportedSearchOperator extends DomainException implements InvalidSearchCriteria
{
    public const string TYPE = 'unsupported-search-operator';

    public static function forField(string $field, FilterOperator $operator): self
    {
        return new self(
            type: self::TYPE,
            title: 'Unsupported search operator.',
            context: ['field' => $field, 'operator' => $operator->value],
        );
    }
}
