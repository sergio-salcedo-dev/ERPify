<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Search;

/**
 * Read surface of a paginated search cursor.
 *
 * Encodes positional state (current page, first and last item identifiers)
 * and an optional total count. Used by callers to render pagination
 * metadata and by serializers to round-trip the cursor across requests.
 */
interface SearchCursor
{
    public function getCurrentPage(): ?int;

    public function getCount(): ?int;

    /** @return array<string, mixed> */
    public function getFirstItem(): array;

    /** @return array<string, mixed> */
    public function getLastItem(): array;
}
