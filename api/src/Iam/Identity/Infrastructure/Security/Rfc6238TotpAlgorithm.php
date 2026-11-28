<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\TotpAlgorithm;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see TotpAlgorithm} implemented in-house over `hash_hmac`, so the second factor adds no runtime dependency:
 * RFC 4226 dynamic truncation of an HMAC-SHA1 over the big-endian 64-bit step counter, with RFC 6238's
 * `T = floor(unix time / 30)`. The RFC 6238 appendix B vectors are its unit test.
 *
 * Every candidate in the window is computed and compared with `hash_equals` before the answer is chosen, so the
 * time a refusal takes does not depend on which step, if any, came close.
 */
#[AsAlias(TotpAlgorithm::class)]
final readonly class Rfc6238TotpAlgorithm implements TotpAlgorithm
{
    public const int DIGITS = 6;

    public const int PERIOD_SECONDS = 30;

    /** Steps accepted either side of the current one, absorbing clock drift between the server and the device. */
    public const int WINDOW = 1;

    /** 160 bits, the length RFC 4226 recommends for an HMAC-SHA1 shared secret. */
    public const int SECRET_BYTES = 20;

    public const string ISSUER = 'ERPify';

    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const string CODE_PATTERN = '/^\d{6}$/D';

    #[Override]
    public function newSecret(): string
    {
        return \random_bytes(self::SECRET_BYTES);
    }

    #[Override]
    public function matchingStep(
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        string $code,
        DateTimeImmutable $at,
        ?int $lastAcceptedStep,
    ): ?int {
        $wellFormed = 1 === \preg_match(self::CODE_PATTERN, $code);
        $current = \intdiv($at->getTimestamp(), self::PERIOD_SECONDS);
        $matched = null;

        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; ++$step) {
            $equal = \hash_equals($this->codeAt($secret, \max(0, $step)), $code);
            $fresh = $step >= 0 && (null === $lastAcceptedStep || $step > $lastAcceptedStep);

            if ($wellFormed && $equal && $fresh && null === $matched) {
                $matched = $step;
            }
        }

        return $matched;
    }

    #[Override]
    public function encodedSecret(#[SensitiveParameter] string $secret): string
    {
        $bits = '';

        foreach (\str_split($secret) as $byte) {
            $bits .= \str_pad(\decbin(\ord($byte[0])), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (\str_split($bits, 5) as $quintet) {
            $encoded .= self::BASE32_ALPHABET[(int) \bindec(\str_pad($quintet, 5, '0'))];
        }

        return $encoded;
    }

    #[Override]
    public function provisioningUri(
        #[SensitiveParameter]
        string $secret,
        #[SensitiveParameter]
        string $accountName,
    ): string {
        return 'otpauth://totp/' . \rawurlencode(self::ISSUER . ':' . $accountName) . '?' . \http_build_query(
            [
                'secret' => $this->encodedSecret($secret),
                'issuer' => self::ISSUER,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD_SECONDS,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    /**
     * The code for one step: RFC 4226 section 5.3, truncated to {@see DIGITS}. Public so the RFC 6238 vectors
     * can be checked step by step rather than only through the window.
     */
    public function codeAt(#[SensitiveParameter] string $secret, int $step): string
    {
        $hmac = \hash_hmac('sha1', \pack('J', $step), $secret, true);
        $offset = \ord($hmac[19]) & 0x0F;
        $binary = ((\ord($hmac[$offset]) & 0x7F) << 24)
            | (\ord($hmac[$offset + 1]) << 16)
            | (\ord($hmac[$offset + 2]) << 8)
            | \ord($hmac[$offset + 3]);

        return \str_pad((string) ($binary % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }
}
