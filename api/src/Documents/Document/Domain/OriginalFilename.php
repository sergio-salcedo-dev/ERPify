<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain;

use Erpify\Documents\Document\Domain\Exception\InvalidOriginalFilename;
use IntlChar;
use LogicException;
use Normalizer;
use Override;
use Stringable;
use WeakMap;

/**
 * The caller's original filename, validated and held in request memory until the commit transaction seals it
 * under the document's own scope. It is a sealed value, never a location: no code builds a path from it, and
 * `/` and `\` are kept as inert text because a consumer sanitises on output.
 *
 * The rules, in the order they are checked (valid UTF-8 first, so every later rule reads code points):
 *  - valid UTF-8, already in NFC (a name that changes under normalisation would round-trip as a different
 *    byte sequence from the one the caller sent);
 *  - 1 to 255 bytes, and not empty once Unicode whitespace is trimmed from both ends;
 *  - no code point of general category Cc, Cs, Co, Cn, Zl or Zp, and no format character (Cf) other than the
 *    zero-width joiner and non-joiner, which real scripts need — so the bidi controls, the other zero-width
 *    characters and the tag characters are refused.
 *
 * It never prints itself: `__toString()` and `__debugInfo()` answer a fixed placeholder, so a value that reaches a
 * log context, an exception message, `var_dump`/`print_r` or a VarDumper dump by accident carries no name. The
 * bytes are not a property of the instance at all but an entry of a static weak map keyed by it — no dumper,
 * `var_export` or array cast prints static state, and the entry dies with the instance — and serialising or
 * cloning the value is refused outright. {@see value()} is the one way to the bytes.
 */
final class OriginalFilename implements Stringable
{
    public const int MAX_BYTES = 255;

    private const string PLACEHOLDER = '[original filename]';

    private const int ZERO_WIDTH_NON_JOINER = 0x200C;

    private const int ZERO_WIDTH_JOINER = 0x200D;

    /** @var list<int> */
    private const array REFUSED_CATEGORIES = [
        IntlChar::CHAR_CATEGORY_CONTROL_CHAR,
        IntlChar::CHAR_CATEGORY_SURROGATE,
        IntlChar::CHAR_CATEGORY_PRIVATE_USE_CHAR,
        IntlChar::CHAR_CATEGORY_UNASSIGNED,
        IntlChar::CHAR_CATEGORY_LINE_SEPARATOR,
        IntlChar::CHAR_CATEGORY_PARAGRAPH_SEPARATOR,
    ];

    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $values = null;

    private function __construct(string $value)
    {
        $this->values()[$this] = $value;
    }

    private function __clone()
    {
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => self::PLACEHOLDER];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('An original filename is never serialised: it lives in request memory only.');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") the magic method's signature; nothing is ever restored
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('An original filename is never unserialised: it lives in request memory only.');
    }

    #[Override]
    public function __toString(): string
    {
        return self::PLACEHOLDER;
    }

    /**
     * @throws InvalidOriginalFilename when any rule is broken; the exception carries no part of the value
     */
    public static function fromString(string $candidate): self
    {
        if (!\mb_check_encoding($candidate, 'UTF-8')) {
            throw InvalidOriginalFilename::create();
        }

        $length = \strlen($candidate);

        if (0 === $length || $length > self::MAX_BYTES || !Normalizer::isNormalized($candidate, Normalizer::NFC)) {
            throw InvalidOriginalFilename::create();
        }

        $codePoints = \array_map(
            static fn (string $character): int => (int) IntlChar::ord($character),
            \mb_str_split($candidate, 1, 'UTF-8'),
        );

        if (\array_any($codePoints, self::isRefused(...)) || self::isBlank($codePoints)) {
            throw InvalidOriginalFilename::create();
        }

        return new self($candidate);
    }

    public function value(): string
    {
        return $this->values()[$this] ?? throw new LogicException('An original filename lost its value.');
    }

    /**
     * @return WeakMap<self, string>
     */
    private function values(): WeakMap
    {
        return self::$values ??= new WeakMap();
    }

    private static function isRefused(int $codePoint): bool
    {
        $category = IntlChar::charType($codePoint);

        if (IntlChar::CHAR_CATEGORY_FORMAT_CHAR === $category) {
            return self::ZERO_WIDTH_NON_JOINER !== $codePoint && self::ZERO_WIDTH_JOINER !== $codePoint;
        }

        return \in_array($category, self::REFUSED_CATEGORIES, true);
    }

    /**
     * Trimming whitespace from both ends leaves nothing exactly when every code point is whitespace.
     *
     * @param list<int> $codePoints
     */
    private static function isBlank(array $codePoints): bool
    {
        return \array_all($codePoints, static fn (int $codePoint): bool => IntlChar::isUWhiteSpace($codePoint));
    }
}
