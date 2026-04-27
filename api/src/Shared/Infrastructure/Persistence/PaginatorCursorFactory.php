<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

/**
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
class PaginatorCursorFactory
{
    /**
     * @param string|null $string base64 of zlib-compressed JSON cursor payload
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    public function createFromString(?string $string): PaginatorCursorInterface
    {
        if (null === $string) {
            return new PaginatorCursor();
        }

        $string = \trim($string);

        if ('' === $string) {
            return new PaginatorCursor();
        }

        $decoded = \base64_decode($string, true);

        if (false === $decoded || '' === $decoded) {
            return new PaginatorCursor();
        }

        $json = @\gzuncompress($decoded);

        if (false === $json) {
            return new PaginatorCursor();
        }

        $cursorData = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($cursorData)) {
            return new PaginatorCursor();
        }

        $currentPage = $cursorData['currentPage'] ?? null;
        $count = $cursorData['count'] ?? null;
        $firstItem = $cursorData['firstItem'] ?? [];
        $lastItem = $cursorData['lastItem'] ?? [];

        return new PaginatorCursor(
            \is_numeric($currentPage) ? (int) $currentPage : null,
            \is_numeric($count) ? (int) $count : null,
            \is_array($firstItem) ? $this->normalizeItem($firstItem) : [],
            \is_array($lastItem) ? $this->normalizeItem($lastItem) : [],
        );
    }

    /**
     * @param array<mixed, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        $normalized = [];

        foreach ($item as $key => $value) {
            if (\is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
