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
            foreach ((array) \glob($temporaryDirectory . '/*') as $file) {
                if (\is_string($file) && \is_file($file)) {
                    \unlink($file);
                }
            }

            \rmdir($temporaryDirectory);
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
