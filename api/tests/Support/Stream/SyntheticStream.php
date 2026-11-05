<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\Stream;

use RuntimeException;

// phpcs:disable PSR1.Methods.CamelCapsMethodName -- the `stream_*` names are the streamWrapper protocol's

/**
 * PHP stream wrapper that stands in for a multi-hundred-megabyte file without holding it, so a test can push
 * an original of any size through the sealing sibling while memory stays the only thing it measures.
 *
 * A source (`SyntheticStream::source()`) yields `$length` deterministic, non-repeating-at-chunk-scale bytes
 * and, when told to, throws on the first read past `$failBeyond` — the shape of the byte counter an upload
 * puts in front of the seal. A sink (`SyntheticStream::sink()`) keeps nothing but a running SHA-256 and a byte
 * count, so a decrypt of `MAX_ORIGINAL_BYTES` is compared byte-identical without being stored.
 *
 * @SuppressWarnings("PHPMD.CamelCaseMethodName") the `stream_*` method names and their parameter lists are
 * the contract PHP's `streamWrapper` protocol dictates
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") see above
 */
final class SyntheticStream
{
    private const string PROTOCOL = 'erpify-synthetic';

    private const int PATTERN_PERIOD = 65521;

    private static ?string $pattern = null;

    /** @var resource|null set by PHP when the stream is opened with a context */
    public $context;

    private ?SyntheticSink $sink = null;

    private int $offset = 0;

    private int $length = 0;

    private ?int $failBeyond = null;

    /**
     * @return resource
     */
    public static function source(int $length, ?int $failBeyond = null)
    {
        self::register();
        $stream = \fopen(self::PROTOCOL . '://source', 'r', false, \stream_context_create([
            self::PROTOCOL => ['length' => $length, 'failBeyond' => $failBeyond],
        ]));

        if (false === $stream) {
            throw new RuntimeException('Could not open the synthetic source.');
        }

        return $stream;
    }

    /**
     * @return resource
     */
    public static function sink(SyntheticSink $sink)
    {
        self::register();
        $stream = \fopen(self::PROTOCOL . '://sink', 'w', false, \stream_context_create([
            self::PROTOCOL => ['sink' => $sink],
        ]));

        if (false === $stream) {
            throw new RuntimeException('Could not open the synthetic sink.');
        }

        return $stream;
    }

    /** SHA-256 (hex) of the bytes a source of this length yields. */
    public static function digestOf(int $length): string
    {
        $context = \hash_init('sha256');

        for ($offset = 0; $offset < $length; $offset += self::PATTERN_PERIOD) {
            \hash_update($context, self::bytesAt($offset, \min(self::PATTERN_PERIOD, $length - $offset)));
        }

        return \hash_final($context);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $options = \stream_context_get_options($this->context ?? \stream_context_get_default());
        $settings = \is_array($options[self::PROTOCOL] ?? null) ? $options[self::PROTOCOL] : [];
        $sink = $settings['sink'] ?? null;
        $this->sink = $sink instanceof SyntheticSink ? $sink : null;
        $this->length = \is_int($settings['length'] ?? null) ? $settings['length'] : 0;
        $this->failBeyond = \is_int($settings['failBeyond'] ?? null) ? $settings['failBeyond'] : null;

        return true;
    }

    public function stream_read(int $count): string
    {
        $count = \min($count, $this->length - $this->offset);

        if ($count <= 0) {
            return '';
        }

        if (null !== $this->failBeyond && $this->offset + $count > $this->failBeyond) {
            throw new RuntimeException('The source ran past its declared size.');
        }

        $bytes = self::bytesAt($this->offset, $count);
        $this->offset += $count;

        return $bytes;
    }

    public function stream_write(string $data): int
    {
        $this->sink?->take($data);

        return \strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->offset >= $this->length;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    private static function register(): void
    {
        if (!\in_array(self::PROTOCOL, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    private static function bytesAt(int $offset, int $count): string
    {
        if (null === self::$pattern) {
            $period = '';

            for ($block = 0; \strlen($period) < self::PATTERN_PERIOD; ++$block) {
                $period .= \hash('sha256', 'erpify-synthetic-' . $block, true);
            }

            $period = \substr($period, 0, self::PATTERN_PERIOD);
            self::$pattern = $period . $period;
        }

        $bytes = '';

        while (\strlen($bytes) < $count) {
            $bytes .= \substr(
                self::$pattern,
                ($offset + \strlen($bytes)) % self::PATTERN_PERIOD,
                \min($count - \strlen($bytes), self::PATTERN_PERIOD),
            );
        }

        return $bytes;
    }
}
