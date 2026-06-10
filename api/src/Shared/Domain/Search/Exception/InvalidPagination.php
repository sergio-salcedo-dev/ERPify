<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvalidSearchCriteria;

/**
 * Thrown by {@see \Erpify\Shared\Domain\Search\SearchCriteria} when its pagination
 * invariants are violated — a page or a limit outside its allowed [1, max] range.
 *
 * These invariants hold for every adapter that builds a SearchCriteria (HTTP, CLI,
 * message handlers), not just the HTTP boundary DTO whose `#[Assert\…]` constraints
 * reject the same values with a 422 before `toCriteria()` ever runs. Implements the
 * {@see InvalidSearchCriteria} marker so the Problem Details pipeline maps it to HTTP 400
 * with no extra wiring, the same as the other search-criteria violations. Only the
 * offending integer travels in `context` — a bare number, never client-identifying input.
 */
final class InvalidPagination extends DomainException implements InvalidSearchCriteria
{
    public const string TYPE = 'invalid-pagination';

    public static function pageOutOfRange(int $page, int $max): self
    {
        return new self(
            type: self::TYPE,
            title: 'Page must be between 1 and the maximum allowed.',
            context: ['page' => $page, 'max' => $max],
        );
    }

    public static function limitOutOfRange(int $limit, int $max): self
    {
        return new self(
            type: self::TYPE,
            title: 'Limit must be between 1 and the maximum allowed.',
            context: ['limit' => $limit, 'max' => $max],
        );
    }
}
