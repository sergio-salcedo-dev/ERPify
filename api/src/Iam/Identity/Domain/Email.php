<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain;

use Erpify\Iam\Identity\Domain\Exception\InvalidEmail;
use Normalizer;
use SensitiveParameter;

/**
 * Canonical form of a user's email identifier: Unicode-normalised, edge-trimmed and lower-cased so the UNIQUE
 * constraint and the case-insensitive login lookup share one definition of "the same email". This value object
 * owns only that canonical shape; RFC format validity is enforced at the application boundary by
 * `#[Assert\Email]` on the aggregate (the framework validator never runs inside the domain).
 *
 * **It is the canonicalisation function of a database constraint, which is why it lives here and nowhere
 * else.** The value written to the unique column is produced by this class, and every lookup that resolves an
 * identity — the user provider, the login-attempt registrar, the password-recovery request — resolves through
 * it too. Split the function across two layers and the write path and the read path acquire the freedom to
 * disagree about what "the same email" means, which is the defect this class exists to prevent rather than a
 * hypothetical.
 */
final readonly class Email
{
    /**
     * Whitespace is removed at the ENDS ONLY, and that is a correctness boundary rather than a preference: a
     * quoted local part is RFC-valid and the aggregate's `#[Assert\Email]` runs in strict mode, so sweeping
     * the interior would canonicalise `"john doe"@x` and `"johndoe"@x` — two distinct mailboxes — onto one
     * value, over a column declared unique.
     *
     * The class is wider than `\p{Z}` on purpose. `\s` covers the ASCII set `trim()` already handled; `\p{Z}`
     * adds the Unicode separators, of which U+00A0 is the one a rich-text paste actually produces; and the
     * explicit range covers the invisible formatting characters that are category `Cf`, **not** `Z`, so no
     * space class matches them — U+FEFF above all, because that is what a UTF-8 BOM decodes to and therefore
     * the likeliest character to lead a pasted value.
     */
    private const string INVISIBLE = '\s\p{Z}\x{FEFF}\x{200B}-\x{200D}';

    private const string EDGE_WHITESPACE = '/^[' . self::INVISIBLE . ']+|[' . self::INVISIBLE . ']+$/u';

    /**
     * @param non-empty-string $value
     */
    private function __construct(#[SensitiveParameter] private string $value)
    {
    }

    /**
     * @throws InvalidEmail when the value is blank, or is not valid UTF-8
     */
    public static function from(#[SensitiveParameter] string $raw): self
    {
        $canonical = self::canonicalise($raw);

        if ('' === $canonical) {
            throw new InvalidEmail();
        }

        return new self($canonical);
    }

    /**
     * The lenient twin of {@see from()} for flows whose contract is silence, not an error: a blank value
     * yields null instead of an exception, so the caller folds it into its uniform outcome with no try/catch.
     */
    public static function tryFrom(#[SensitiveParameter] string $raw): ?self
    {
        try {
            return self::from($raw);
        } catch (InvalidEmail) {
            return null;
        }
    }

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        return $this->value;
    }

    public function equals(#[SensitiveParameter] self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Normalise, trim the ends, lower-case, normalise again. The closing pass is not ceremony: case folding is
     * defined per code point and is not obliged to return a composed sequence, so the cheaper single-pass
     * ordering would leave the stored value's normal form dependent on which script the address is written in.
     * The property that matters is that the OUTPUT is NFC — asserted by its own test rather than inferred from
     * the order of these four lines — and one extra pass over at most 255 characters is what makes it hold
     * whatever the input.
     *
     * A malformed-UTF-8 argument leaves here as blank, so `from()` refuses it through the same door as an empty
     * one. That is the honest outcome: bytes that are not text cannot denote a mailbox, and the callers that
     * resolve an identity through this class already answer a uniform refusal.
     */
    private static function canonicalise(#[SensitiveParameter] string $raw): string
    {
        $normalised = Normalizer::normalize($raw, Normalizer::FORM_C);

        if (!\is_string($normalised)) {
            return '';
        }

        $lowered = \mb_strtolower((string) \preg_replace(self::EDGE_WHITESPACE, '', $normalised));
        $recomposed = Normalizer::normalize($lowered, Normalizer::FORM_C);

        return \is_string($recomposed) ? $recomposed : '';
    }
}
