<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Mailer\Infrastructure;

use Erpify\Shared\Mailer\Infrastructure\BulletproofEmailChrome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BulletproofEmailChrome::class)]
final class BulletproofEmailChromeTest extends TestCase
{
    public function testWrapsTheInnerHtmlInTheSharedEnvelope(): void
    {
        $html = (new BulletproofEmailChrome())->render('<p>inner-marker</p>');

        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('<html lang="es">', $html);
        $this->assertStringContainsString('<p>inner-marker</p>', $html);
    }

    public function testCarriesTheDarkModeStylesAndTheSystemFontStack(): void
    {
        $html = (new BulletproofEmailChrome())->render('');

        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString(
            "font-family:-apple-system,system-ui,'Segoe UI',Roboto,sans-serif",
            $html,
        );
        $this->assertStringNotContainsString('Arial', $html);
    }

    public function testEscapeNeutralisesMarkupAndQuotes(): void
    {
        $escaped = (new BulletproofEmailChrome())->escape('<a href="x">\'&</a>');

        $this->assertSame('&lt;a href=&quot;x&quot;&gt;&#039;&amp;&lt;/a&gt;', $escaped);
    }
}
