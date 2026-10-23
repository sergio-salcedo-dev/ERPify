<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use FilesystemIterator;
use Override;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Scratch directory, fixture env files, a throwaway git repository and {@see ProdEnvScript} runs, shared by the
 * gates that execute `scripts/deploy/lib/prod-env.sh`.
 */
abstract class ProdEnvScriptTestCase extends TestCase
{
    protected string $scratch = '';

    #[Override]
    protected function setUp(): void
    {
        $this->scratch = \sys_get_temp_dir() . '/prod-env-gate-' . \bin2hex(\random_bytes(6));
        \mkdir($this->scratch);
    }

    #[Override]
    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->scratch, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }

        \rmdir($this->scratch);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     * @param list<string>          $emptyExports
     */
    protected function answer(array $arguments, array $environment = [], array $emptyExports = []): string
    {
        $result = ProdEnvScript::run($this->root(), $arguments, $environment, $emptyExports);
        $this->assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);

        return \trim($result['stdout'], "\n");
    }

    /**
     * @param list<string> $arguments
     */
    protected function git(array $arguments): void
    {
        $process = \proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $err = $pipes[2] ?? null;
        $this->assertIsResource($err);
        $stderr = (string) \stream_get_contents($err);
        $this->assertSame(0, \proc_close($process), 'git ' . \implode(' ', $arguments) . ': ' . $stderr);
    }

    protected function envFile(string $contents): string
    {
        $file = \tempnam($this->scratch, 'env');
        $this->assertIsString($file);
        \file_put_contents($file, $contents);

        return $file;
    }

    protected function root(): string
    {
        $root = RepositoryRoot::path();
        $this->assertNotNull($root, 'Cannot find the repository root (the /app/repo mount in compose.dev.yaml).');

        return $root;
    }
}
