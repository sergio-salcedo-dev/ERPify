<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain;

use Closure;
use Erpify\Documents\Document\Application\Upload\ClaimedUpload;
use Erpify\Documents\Document\Domain\Exception\InvalidOriginalFilename;
use Erpify\Documents\Document\Domain\FilenameSubmission;
use Erpify\Documents\Document\Domain\OriginalFilename;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

/**
 * @internal
 */
#[CoversClass(OriginalFilename::class)]
#[CoversClass(FilenameSubmission::class)]
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

    /**
     * @param Closure(object): string $dump
     */
    #[DataProvider('provideNoDumpOfItOrOfWhatHoldsItCarriesTheNameCases')]
    public function testNoDumpOfItOrOfWhatHoldsItCarriesTheName(Closure $dump): void
    {
        $name = OriginalFilename::fromString('Nombre de una persona.pdf');

        foreach ($this->holders($name) as $holder) {
            $printed = $dump($holder);

            $this->assertStringNotContainsString('persona', $printed, $holder::class);
            $this->assertNotSame('', $printed);
        }
    }

    /**
     * @return iterable<string, array{Closure(object): string}>
     *
     * @SuppressWarnings("PHPMD.DevelopmentCodeFragment") the debug dumpers ARE the sinks under test
     */
    public static function provideNoDumpOfItOrOfWhatHoldsItCarriesTheNameCases(): iterable
    {
        yield 'var_dump' => [static function (object $value): string {
            \ob_start();
            \var_dump($value);

            return (string) \ob_get_clean();
        }];
        yield 'print_r' => [static fn (object $value): string => \print_r($value, true)];
        yield 'var_export' => [static fn (object $value): string => \var_export($value, true)];
        yield 'an array cast' => [static fn (object $value): string => \print_r((array) $value, true)];
        yield 'VarDumper' => [static fn (object $value): string => (string) (new CliDumper())->dump(
            (new VarCloner())->cloneVar($value),
            true,
        )];
    }

    public function testNeitherItNorWhatHoldsItCanBeSerialised(): void
    {
        foreach ($this->holders(OriginalFilename::fromString('Nombre de una persona.pdf')) as $holder) {
            try {
                \serialize($holder);
                $this->fail($holder::class . ' was serialised with the name in it.');
            } catch (LogicException $logicException) {
                $this->assertStringNotContainsString('persona', $logicException->getMessage());
            }
        }
    }

    public function testItCannotBeRevivedFromASerialisedForm(): void
    {
        $this->expectException(LogicException::class);

        \unserialize('O:' . \strlen(OriginalFilename::class) . ':"' . OriginalFilename::class . '":0:{}');
    }

    /**
     * @return list<object>
     */
    private function holders(OriginalFilename $name): array
    {
        return [
            $name,
            FilenameSubmission::of($name),
            new ClaimedUpload('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c', 'scope', 10, null, $name),
        ];
    }
}
