<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain;

use Erpify\Documents\Document\Domain\Exception\InvalidOriginalFilename;
use Erpify\Documents\Document\Domain\OriginalFilename;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OriginalFilename::class)]
final class OriginalFilenameTest extends TestCase
{
    #[DataProvider('provideItAcceptsAndKeepsTheBytesCases')]
    public function testItAcceptsAndKeepsTheBytes(string $name): void
    {
        $this->assertSame($name, OriginalFilename::fromString($name)->value());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItAcceptsAndKeepsTheBytesCases(): iterable
    {
        yield 'plain' => ['report.pdf'];
        yield 'accented, NFC' => ["Acta de replanteo \u{00E9}.pdf"];
        yield 'exactly 255 bytes' => [\str_repeat('a', 251) . '.pdf'];
        yield 'slashes are inert text' => ['a/b\c.dwg'];
        yield 'zero-width joiner' => ["\u{0915}\u{094D}\u{200D}\u{0937}.pdf"];
        yield 'zero-width non-joiner' => ["\u{0645}\u{200C}\u{06CC}.pdf"];
        yield 'one byte' => ['a'];
    }

    #[DataProvider('provideItRefusesWithoutQuotingTheValueCases')]
    public function testItRefusesWithoutQuotingTheValue(string $name): void
    {
        try {
            OriginalFilename::fromString($name);
            $this->fail('The name was accepted.');
        } catch (InvalidOriginalFilename $invalidOriginalFilename) {
            $this->assertSame('invalid-filename', $invalidOriginalFilename->type());
            $this->assertSame([], $invalidOriginalFilename->context());

            if ('' !== \trim($name)) {
                $this->assertStringNotContainsString($name, $invalidOriginalFilename->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItRefusesWithoutQuotingTheValueCases(): iterable
    {
        yield 'empty' => [''];
        yield 'only whitespace' => ["  \u{3000}\t"];
        yield '256 bytes' => [\str_repeat('a', 252) . '.pdf'];
        yield 'invalid UTF-8' => ["report\xC3.pdf"];
        yield 'not NFC' => ["Acta e\u{0301}.pdf"];
        yield 'control character (Cc)' => ["report\x01.pdf"];
        yield 'NUL' => ["report\x00.pdf"];
        yield 'bidi override (Cf)' => ["invoice\u{202E}fdp.exe"];
        yield 'zero-width space (Cf)' => ["a\u{200B}b.pdf"];
        yield 'tag character (Cf)' => ["a\u{E0041}.pdf"];
        yield 'line separator (Zl)' => ["a\u{2028}b"];
        yield 'paragraph separator (Zp)' => ["a\u{2029}b"];
        yield 'private use (Co)' => ["a\u{E000}b"];
        yield 'unassigned (Cn)' => ["a\u{0378}b"];
    }

    public function testItNeverPrintsItself(): void
    {
        $name = OriginalFilename::fromString('Nombre de una persona.pdf');

        $this->assertStringNotContainsString('persona', (string) $name);
    }
}
