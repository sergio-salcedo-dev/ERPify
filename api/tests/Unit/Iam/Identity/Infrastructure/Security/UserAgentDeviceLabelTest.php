<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\UserAgentDeviceLabel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UserAgentDeviceLabel::class)]
final class UserAgentDeviceLabelTest extends TestCase
{
    #[DataProvider('provideItNormalisesKnownAgentsToACoarseLabelCases')]
    public function testItNormalisesKnownAgentsToACoarseLabel(?string $userAgent, string $expected): void
    {
        $this->assertSame($expected, UserAgentDeviceLabel::fromUserAgent($userAgent));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function provideItNormalisesKnownAgentsToACoarseLabelCases(): iterable
    {
        yield 'Chrome on macOS' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/126.0.0.0 Safari/537.36',
            'Chrome on macOS',
        ];
        yield 'Edge beats the Chrome token it also carries' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0',
            'Edge on Windows',
        ];
        yield 'Firefox on Linux' => [
            'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0',
            'Firefox on Linux',
        ];
        yield 'Safari on iOS' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
            . 'Version/17.5 Mobile/15E148 Safari/604.1',
            'Safari on iOS',
        ];
        yield 'a null user agent is unknown' => [null, 'Unknown device'];
        yield 'a blank user agent is unknown' => ['   ', 'Unknown device'];
        yield 'an unrecognised agent is unknown' => ['curl/8.4.0', 'Unknown device'];
    }
}
