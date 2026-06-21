<?php

declare(strict_types=1);

namespace Erpify\Shared\ErrorContract\Domain\Exception;

use Throwable;

/**
 * Base class for domain exceptions targeted at an RFC 9457 ProblemDetails mapping.
 *
 * - {@see DomainException::type()} returns the opaque, stable identifier surfaced as
 *   ProblemDetails `type` (subclasses may override).
 * - {@see DomainException::title()} returns a short, human-readable summary, also exposed
 *   via {@see Throwable::getMessage()}.
 * - {@see DomainException::context()} returns the app-defined extensions map; downstream
 *   layers are responsible for redaction before serialization.
 */
abstract class DomainException extends \DomainException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly string $type,
        private readonly string $title,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(message: $title, code: 0, previous: $previous);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
