<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * The on-disk fixtures the `docs/project-context.md` gates are falsified against: a throwaway manifest, a
 * throwaway registry, and a registry entry built by hand.
 *
 * The rules are pinned in three classes, one per subject the gate reads — the manifest, the page and the
 * registry — and all three need to write a file somewhere and be sure it is gone afterwards. Each run
 * creates one directory per fixture, so leaving them behind fills the container's temp with dozens of
 * directories nobody can attribute to anything.
 *
 * The sweep READS the directory rather than globbing it, because one of the fixtures is a dotfile
 * (`.project-context-versions`) and `glob('*')` does not match one — measured, that spelling left three
 * directories behind on every run while reporting nothing. `rmdir()` is asserted for the same reason: on a
 * non-empty directory it only warns, and `tools/phpunit/phpunit.dist.xml` restricts warning failures to
 * `src`, so the next unreachable name would be exactly as quiet as this one was.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 *
 * @internal test support
 */
trait ProjectContextFixtureFiles
{
    /**
     * @var list<string>
     */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $temporaryDirectory) {
            foreach (\array_diff((array) \scandir($temporaryDirectory), ['.', '..']) as $entry) {
                \unlink($temporaryDirectory . '/' . $entry);
            }

            $this->assertTrue(\rmdir($temporaryDirectory), \sprintf(
                'The fixture directory %s survived its test. Something wrote a name this cleanup does not '
                . 'reach, and the next leak would be as quiet as this one.',
                $temporaryDirectory,
            ));
        }

        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    /**
     * @return array{manifest: string, path: string, token: string, version: string}
     */
    private function entry(string $manifest, string $path, string $token): array
    {
        $words = \explode(' ', $token);

        return [
            'manifest' => $manifest,
            'path' => $path,
            'token' => $token,
            'version' => $words[\count($words) - 1],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function fixtureRoot(array $manifest): string
    {
        $root = $this->temporaryDirectory();
        \file_put_contents(
            $root . '/manifest.json',
            \json_encode($manifest, JSON_THROW_ON_ERROR),
        );

        return $root;
    }

    private function registryHolding(string $contents): string
    {
        $path = $this->temporaryDirectory() . '/.project-context-versions';
        \file_put_contents($path, $contents);

        return $path;
    }

    private function temporaryDirectory(): string
    {
        $root = \sys_get_temp_dir() . '/project-context-versions-' . \bin2hex(\random_bytes(8));
        $this->assertTrue(\mkdir($root, 0o700, true));
        $this->temporaryDirectories[] = $root;

        return $root;
    }
}
