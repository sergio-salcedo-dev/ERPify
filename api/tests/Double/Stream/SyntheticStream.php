<?php

declare(strict_types=1);

namespace Erpify\Tests\Double\Stream;

use LogicException;
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
 * Both ends can misbehave the way a real stream does: a source can hand back a few bytes per read, answer
 * nothing before it has anything to say, or stall (nothing, and still no end) from a given offset on; a sink
 * can accept fewer bytes than it was given, run out of room, or refuse to flush. `over()` serves given bytes
 * (a sealed object, say) through the same source behaviour.
 *
 * @SuppressWarnings("PHPMD.CamelCaseMethodName") the `stream_*` method names and their parameter lists are
 * the contract PHP's `streamWrapper` protocol dictates
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") see above
 */
final class SyntheticStream
{
    private const string PROTOCOL = 'erpify-synthetic';

    private const int PATTERN_PERIOD = 65521;

    private const int MAX_STALLED_POLLS = 1000;

    private static ?string $pattern = null;

    /** @var resource|null set by PHP when the stream is opened with a context */
    public $context;

    private ?SyntheticSink $sink = null;

    private int $offset = 0;

    private int $accepted = 0;

    private int $stalledPolls = 0;

    private int $length = 0;

    private ?int $failBeyond = null;

    private ?string $contents = null;

    private ?int $maxBytesPerRead = null;

    private int $emptyReadsLeft = 0;

    private ?int $stallsAfter = null;

    private ?int $maxBytesPerWrite = null;

    private ?int $capacity = null;

    private bool $flushFails = false;

    /**
     * @param int|null $failBeyond      throw on the first read that would pass this offset
     * @param int|null $maxBytesPerRead hand back at most this many bytes per read
     * @param int      $emptyReadsFirst answer this many reads with nothing, without reporting the end
     * @param int|null $stallsAfter     from this offset on answer every read with nothing, without an end
     *
     * @return resource
     */
    public static function source(
        int $length,
        ?int $failBeyond = null,
        ?int $maxBytesPerRead = null,
        int $emptyReadsFirst = 0,
        ?int $stallsAfter = null,
    ) {
        return self::open('source', 'r', [
            'length' => $length,
            'failBeyond' => $failBeyond,
            'maxBytesPerRead' => $maxBytesPerRead,
            'emptyReadsFirst' => $emptyReadsFirst,
            'stallsAfter' => $stallsAfter,
        ]);
    }

    /**
     * A source over the given bytes, with the same misbehaviour switches as {@see source()}.
     *
     * @return resource
     */
    public static function over(
        string $contents,
        ?int $failBeyond = null,
        ?int $maxBytesPerRead = null,
        int $emptyReadsFirst = 0,
        ?int $stallsAfter = null,
    ) {
        return self::open('source', 'r', [
            'contents' => $contents,
            'length' => \strlen($contents),
            'failBeyond' => $failBeyond,
            'maxBytesPerRead' => $maxBytesPerRead,
            'emptyReadsFirst' => $emptyReadsFirst,
            'stallsAfter' => $stallsAfter,
        ]);
    }

    /**
     * @param int|null $maxBytesPerWrite accept at most this many bytes per write
     * @param int|null $capacity         accept this many bytes in all, then take nothing more
     *
     * @return resource
     */
    public static function sink(SyntheticSink $sink, ?int $maxBytesPerWrite = null, ?int $capacity = null)
    {
        return self::open('sink', 'w', [
            'sink' => $sink,
            'maxBytesPerWrite' => $maxBytesPerWrite,
            'capacity' => $capacity,
        ]);
    }

    /**
     * A sink that takes every byte and reports a failed flush.
     *
     * @return resource
     */
    public static function unflushableSink(SyntheticSink $sink)
    {
        return self::open('sink', 'w', ['sink' => $sink, 'flushFails' => true]);
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
        $this->length = $this->intSetting($settings, 'length') ?? 0;
        $this->failBeyond = $this->intSetting($settings, 'failBeyond');
        $this->contents = \is_string($settings['contents'] ?? null) ? $settings['contents'] : null;
        $this->maxBytesPerRead = $this->intSetting($settings, 'maxBytesPerRead');
        $this->emptyReadsLeft = $this->intSetting($settings, 'emptyReadsFirst') ?? 0;
        $this->stallsAfter = $this->intSetting($settings, 'stallsAfter');
        $this->maxBytesPerWrite = $this->intSetting($settings, 'maxBytesPerWrite');
        $this->capacity = $this->intSetting($settings, 'capacity');
        $this->flushFails = true === ($settings['flushFails'] ?? false);

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->emptyReadsLeft > 0) {
            --$this->emptyReadsLeft;

            return '';
        }

        if ($this->stalled()) {
            // A reader with no way out would spin here for ever; failing the poll that proves it turns a
            // hung test run into a red test.
            if (++$this->stalledPolls > self::MAX_STALLED_POLLS) {
                throw new LogicException('The stalled source was polled past any sane retry budget.');
            }

            return '';
        }

        $count = \min($count, $this->length - $this->offset, $this->maxBytesPerRead ?? $count);

        if ($count <= 0) {
            return '';
        }

        if (null !== $this->failBeyond && $this->offset + $count > $this->failBeyond) {
            throw new RuntimeException('The source ran past its declared size.');
        }

        $bytes = null === $this->contents
            ? self::bytesAt($this->offset, $count)
            : \substr($this->contents, $this->offset, $count);
        $this->offset += $count;

        return $bytes;
    }

    public function stream_write(string $data): int
    {
        $accepted = \min(\strlen($data), $this->maxBytesPerWrite ?? \strlen($data));

        if (null !== $this->capacity) {
            $accepted = \min($accepted, $this->capacity - $this->accepted);
        }

        $this->sink?->take(\substr($data, 0, $accepted));
        $this->accepted += $accepted;

        return $accepted;
    }

    public function stream_flush(): bool
    {
        return !$this->flushFails;
    }

    public function stream_eof(): bool
    {
        return !$this->stalled() && $this->offset >= $this->length;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return resource
     */
    private static function open(string $role, string $mode, array $settings)
    {
        self::register();
        $stream = \fopen(self::PROTOCOL . '://' . $role, $mode, false, \stream_context_create([
            self::PROTOCOL => $settings,
        ]));

        if (false === $stream) {
            throw new RuntimeException(\sprintf('Could not open the synthetic %s.', $role));
        }

        return $stream;
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private function intSetting(array $settings, string $key): ?int
    {
        return \is_int($settings[$key] ?? null) ? $settings[$key] : null;
    }

    private function stalled(): bool
    {
        return null !== $this->stallsAfter && $this->offset >= $this->stallsAfter;
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
