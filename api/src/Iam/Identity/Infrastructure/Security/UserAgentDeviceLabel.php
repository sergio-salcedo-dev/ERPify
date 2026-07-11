<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

/**
 * Normalises a request `User-Agent` into a coarse, bounded device label ("Chrome on macOS") for the session
 * registry. Server-side normalisation is a security requirement, not cosmetics: the raw `User-Agent` is
 * attacker-controlled free text, so storing it verbatim would keep client PII and risk stored injection when
 * "my sessions" renders it. Only known browser/OS tokens are recognised; anything else collapses to a neutral
 * literal, and the output can only be one of a small, safe set of strings.
 */
final class UserAgentDeviceLabel
{
    private const string UNKNOWN = 'Unknown device';

    public static function fromUserAgent(?string $userAgent): string
    {
        if (null === $userAgent || '' === \trim($userAgent)) {
            return self::UNKNOWN;
        }

        $browser = self::detectBrowser($userAgent);
        $operatingSystem = self::detectOperatingSystem($userAgent);

        $parts = \array_values(\array_filter([$browser, $operatingSystem]));

        return [] === $parts ? self::UNKNOWN : \implode(' on ', $parts);
    }

    /**
     * Order is load-bearing: an Edge UA also contains "Chrome", and a Chrome UA also contains "Safari", so the
     * more specific token is matched first.
     */
    private static function detectBrowser(string $userAgent): ?string
    {
        return match (true) {
            \str_contains($userAgent, 'Edg/') => 'Edge',
            \str_contains($userAgent, 'OPR/') || \str_contains($userAgent, 'Opera') => 'Opera',
            \str_contains($userAgent, 'Chrome/') => 'Chrome',
            \str_contains($userAgent, 'Firefox/') => 'Firefox',
            \str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
    }

    private static function detectOperatingSystem(string $userAgent): ?string
    {
        return match (true) {
            \str_contains($userAgent, 'Windows NT') => 'Windows',
            \str_contains($userAgent, 'iPhone') || \str_contains($userAgent, 'iPad') => 'iOS',
            \str_contains($userAgent, 'Mac OS X') || \str_contains($userAgent, 'Macintosh') => 'macOS',
            \str_contains($userAgent, 'Android') => 'Android',
            \str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
