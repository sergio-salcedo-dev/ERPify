<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Problem;

/**
 * Wire-shape value object for an RFC 9457 Problem Details body.
 *
 * Owns the deterministic key order (`type`, `title`, `status`, `detail`, `instance`,
 * `correlation-id`, then extension members at the top level) and the camelCase →
 * kebab-case mapping for `correlationId`. Stays framework-free on purpose so it can
 * be constructed and snapshot-tested from anywhere; HTTP wrapping happens upstream.
 */
final readonly class ProblemDetails
{
    /**
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public ?string $detail,
        public string $instance,
        public string $correlationId,
        public array $extensions = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
        ];

        if (null !== $this->detail) {
            $body['detail'] = $this->detail;
        }

        $body['instance'] = $this->instance;
        $body['correlation-id'] = $this->correlationId;

        return $body + $this->extensions;
    }
}
