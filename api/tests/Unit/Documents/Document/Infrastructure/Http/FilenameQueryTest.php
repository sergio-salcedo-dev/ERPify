<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Http;

use Erpify\Documents\Document\Infrastructure\Http\FilenameQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FilenameQuery::class)]
final class FilenameQueryTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideItCountsEveryFilenamePairBeforeDecodingCases')]
    public function testItCountsEveryFilenamePairBeforeDecoding(string $query, array $expected): void
    {
        $this->assertSame($expected, FilenameQuery::valuesIn($query));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideItCountsEveryFilenamePairBeforeDecodingCases(): iterable
    {
        yield 'one, percent-encoded UTF-8' => ['filename=Acta%20%C3%A9.pdf', ['Acta é.pdf']];
        yield 'an unencoded plus is a space, as PHP decodes it' => ['filename=a+b.pdf', ['a b.pdf']];
        yield 'a repeat is counted twice, which the parsed query would hide' => ['filename=a&filename=b', ['a', 'b']];
        yield 'an array spelling counts' => ['filename[]=a', ['a']];
        yield 'an encoded name counts' => ['%66ilename=a', ['a']];
        yield 'other parameters are ignored' => ['x=1&file_name=a', []];
        yield 'none' => ['', []];
        yield 'a name with no value' => ['filename', ['']];
    }
}
