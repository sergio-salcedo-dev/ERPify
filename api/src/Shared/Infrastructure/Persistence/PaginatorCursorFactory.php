<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Erpify\Shared\Domain\Search\SearchCursor;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use UnexpectedValueException;

/**
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
class PaginatorCursorFactory
{
    private const int MAX_DECOMPRESSED_BYTES = 65_536;

    private const string HMAC_ALGO = 'sha256';

    private const string SIGNATURE_SEPARATOR = '.';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /**
     * @param string|null $string `<base64(zlib(json))>.<hex hmac>`
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    public function createFromString(?string $string): PaginatorCursorInterface
    {
        $body = $this->extractVerifiedBody($string);

        if (null === $body) {
            return new PaginatorCursor();
        }

        $decoded = \base64_decode($body, true);

        if (false === $decoded || '' === $decoded) {
            throw new InvalidArgumentException('Cursor body is not valid base64.');
        }

        $json = @\gzuncompress($decoded, self::MAX_DECOMPRESSED_BYTES);

        if (false === $json) {
            throw new InvalidArgumentException('Cursor payload could not be decompressed.');
        }

        try {
            $cursorData = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InvalidArgumentException('Cursor payload is not valid JSON.', 0, $jsonException);
        }

        if (!\is_array($cursorData)) {
            throw new InvalidArgumentException('Cursor payload is not a JSON object.');
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

    public function toString(SearchCursor $cursor): string
    {
        $payload = \json_encode(
            [
                'currentPage' => $cursor->getCurrentPage(),
                'count' => $cursor->getCount(),
                'firstItem' => $cursor->getFirstItem(),
                'lastItem' => $cursor->getLastItem(),
            ],
            JSON_THROW_ON_ERROR,
        );

        $compressed = \gzcompress($payload);

        if (false === $compressed) {
            throw new UnexpectedValueException('Failed to compress paginator cursor payload.');
        }

        $body = \base64_encode($compressed);
        $signature = \hash_hmac(self::HMAC_ALGO, $body, $this->secret);

        return $body . self::SIGNATURE_SEPARATOR . $signature;
    }

    /**
     * Returns the verified body portion of `<body>.<hmac>` after HMAC validation, or
     * `null` if the input is missing, malformed, or the signature does not match.
     * Kept separate so {@see createFromString} reads as a thin "verify, decode, normalise" pipeline.
     */
    private function extractVerifiedBody(?string $string): ?string
    {
        $trimmed = null === $string ? '' : \trim($string);
        $separatorPosition = '' === $trimmed ? false : \strrpos($trimmed, self::SIGNATURE_SEPARATOR);

        if (false === $separatorPosition) {
            return null;
        }

        $body = \substr($trimmed, 0, $separatorPosition);
        $signature = \substr($trimmed, $separatorPosition + 1);
        $expected = \hash_hmac(self::HMAC_ALGO, $body, $this->secret);

        return \hash_equals($expected, $signature) ? $body : null;
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
