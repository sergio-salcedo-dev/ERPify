<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Exception;

use Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Architecture guard for Erpify\Shared\Domain\Exception: enforces that the taxonomy stays
 * free of HTTP, framework, ORM, and transport imports so the domain remains pure.
 *
 * @internal
 */
#[CoversNothing]
final class TaxonomyArchitectureTest extends TestCase
{
    private const array BANNED_PREFIXES = [
        'Symfony\\',
        'Doctrine\\',
        'Psr\Http\\',
        'Symfony\Component\Messenger\\',
        'App\\',
    ];

    #[DataProvider('provideFileHasNoBannedImportsCases')]
    public function testFileHasNoBannedImports(string $file): void
    {
        $contents = \file_get_contents($file);
        $this->assertNotFalse($contents, \sprintf('Cannot read %s', $file));

        $violations = [];

        foreach (\preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
            $imported = $this->extractImportedSymbol($line);

            if (null === $imported) {
                continue;
            }

            foreach (self::BANNED_PREFIXES as $banned) {
                if (\str_starts_with($imported, $banned)) {
                    $violations[] = \sprintf('"%s" (matches banned prefix "%s")', $imported, $banned);
                }
            }
        }

        $this->assertSame([], $violations, \sprintf(
            'File %s must not import HTTP/framework/ORM/transport namespaces. Violations: %s',
            \basename($file),
            \implode('; ', $violations),
        ));
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function provideFileHasNoBannedImportsCases(): iterable
    {
        $apiRoot = \dirname(__DIR__, 5);
        $dir = $apiRoot . '/src/Shared/Domain/Exception';
        $files = \glob($dir . '/*.php');

        if (false === $files || [] === $files) {
            throw new RuntimeException(\sprintf('No taxonomy files found under %s', $dir));
        }

        foreach ($files as $file) {
            yield \basename($file) => [$file];
        }
    }

    private function extractImportedSymbol(string $line): ?string
    {
        $trimmed = \ltrim($line);

        if (!\str_starts_with($trimmed, 'use ')) {
            return null;
        }

        $rest = \ltrim(\substr($trimmed, 4));

        if (\str_starts_with($rest, 'function ')) {
            $rest = \ltrim(\substr($rest, 9));
        } elseif (\str_starts_with($rest, 'const ')) {
            $rest = \ltrim(\substr($rest, 6));
        }

        if (1 !== \preg_match('/^([^\s;{,]+)/', $rest, $matches)) {
            return null;
        }

        return \ltrim($matches[1], '\\');
    }
}
