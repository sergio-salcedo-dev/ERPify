<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\DocumentsTransportBoundary;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * ADR invariant 6 over the Documents tree: no HTTP transport type and no caller-supplied location past
 * `Infrastructure`. The caller's original filename reaches `Application` only as the judged
 * `FilenameSubmission` / sealed `OriginalFilename`, whose two files are the scan's only exclusions.
 *
 * Two halves. The tree half sweeps every PHP file under `src/Documents` outside an `Infrastructure` directory
 * and asserts its own universe (a file floor and both inner layers reached), because a scan that silently
 * stopped matching anything after a rename would report the same green as a clean tree. The regression half
 * feeds the engine planted sources and expects each axis to fire.
 *
 * @internal
 */
#[CoversNothing]
final class DocumentsTransportBoundaryGateTest extends TestCase
{
    private const int FILE_FLOOR = 40;

    /** @var list<string> the value object and its judged submission: sealed values, never locations */
    private const array EXCLUDED = [
        'src/Documents/Document/Domain/OriginalFilename.php',
        'src/Documents/Document/Domain/FilenameSubmission.php',
    ];

    public function testTheDocumentsTreeKeepsTransportTypesAndLocationsAtItsEdge(): void
    {
        $violations = [];
        $scanned = [];

        foreach ($this->innerFiles() as $relative => $source) {
            $scanned[] = $relative;
            $violations = [...$violations, ...DocumentsTransportBoundary::violationsIn($source, $relative)];
        }

        $this->assertGreaterThanOrEqual(self::FILE_FLOOR, \count($scanned), 'the scan lost its universe');
        $this->assertNotEmpty(\array_filter($scanned, static fn (string $f): bool => \str_contains($f, '/Domain/')));
        $this->assertNotEmpty(\array_filter(
            $scanned,
            static fn (string $f): bool => \str_contains($f, '/Application/'),
        ));
        $this->assertSame([], $violations);
    }

    #[DataProvider('provideTheEngineReportsEachPlantedShapeCases')]
    public function testTheEngineReportsEachPlantedShape(string $source): void
    {
        $this->assertNotSame([], DocumentsTransportBoundary::violationsIn($source, 'planted.php'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheEngineReportsEachPlantedShapeCases(): iterable
    {
        yield 'an imported UploadedFile' => [
            '<?php use Symfony\Component\HttpFoundation\File\UploadedFile; function f(UploadedFile $f) {}',
        ];
        yield 'a fully-qualified Request' => ['<?php function f(\Symfony\Component\HttpFoundation\Request $r) {}'];
        yield 'an aliased File' => [
            '<?php use Symfony\Component\HttpFoundation\File\File as Blob; function f(Blob $b) {}',
        ];
        yield 'a bare SplFileInfo' => ['<?php function f() { return new SplFileInfo("x"); }'];
        yield 'a PSR uploaded file' => [
            '<?php use Psr\Http\Message\UploadedFileInterface; function f(UploadedFileInterface $u) {}',
        ];
        yield 'a caller path' => ['<?php function f(string $storagePath) {}'];
        yield 'a caller URL' => ['<?php final class C { public function __construct(public ?string $sourceUrl) {} }'];
        yield 'a raw filename' => ['<?php function f(string $filename) {}'];
        yield 'a list of locations' => ['<?php function f(array $paths) {}'];
    }

    public function testTheSealedFilenameValueIsNotALocation(): void
    {
        $source = '<?php final class C { public function __construct(public OriginalFilename $filename, '
            . 'private \Erpify\Documents\Document\Domain\FilenameSubmission $filename2) {} }';

        $this->assertSame([], DocumentsTransportBoundary::violationsIn($source, 'clean.php'));
    }

    /**
     * @return iterable<string, string>
     */
    private function innerFiles(): iterable
    {
        $root = \dirname(__DIR__, 3);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src/Documents'));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = \substr($file->getPathname(), \strlen($root) + 1);

            if (
                !$file->isFile()
                || 'php' !== $file->getExtension()
                || \str_contains($relative, '/Infrastructure/')
                || \in_array($relative, self::EXCLUDED, true)
            ) {
                continue;
            }

            yield $relative => (string) \file_get_contents($file->getPathname());
        }
    }
}
