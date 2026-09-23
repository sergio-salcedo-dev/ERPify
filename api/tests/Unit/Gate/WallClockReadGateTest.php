<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\WallClockReads;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * `api/src` has ONE time source: the injected `Erpify\Shared\Clock\Domain\Clock` port. The application layer
 * reads it once per operation and hands the instant inward, so an aggregate's `createdAt`, the `occurredOn` of
 * the event it records and any expiry the use case computes are all the same reading. A second source — a
 * bare `new DateTimeImmutable()`, `time()`, a relative literal, Symfony's global clock reached statically —
 * reopens exactly the divergence that design closes, and nothing else goes red when it does: a unit test
 * injecting a clock double passes while production stamps a different instant beside it.
 *
 * The rule and its falsification live in {@see WallClockReadRulesGateTest}; this test applies it to the tree
 * and asserts an EMPTY set rather than a count, since the correct inventory is zero.
 *
 * **One exemption, and it is live or it is red.** `RateLimitSnapshot` subtracts `\time()` from the limiter's
 * `getRetryAfter()`, and that is correct rather than tolerated: Symfony's rate limiter stamps its windows off
 * `microtime()`/`time()` itself, so the delta has to be taken in the limiter's frame. Reading the application
 * clock there would give a wrong `Retry-After` the moment the two disagree. The exemption names its file and
 * is refused once the file no longer holds a read, so it cannot outlive its reason.
 *
 * @internal test support
 */
#[CoversNothing]
final class WallClockReadGateTest extends TestCase
{
    /**
     * @var array<string, string> path relative to `api/` => why the read belongs to another clock's frame
     */
    private const array EXEMPT = [
        'src/Shared/ErrorContract/Infrastructure/Http/RateLimitSnapshot.php' => 'the limiter reads the wall clock',
    ];

    /**
     * A floor rather than a total, so a walk that silently read nothing cannot pass as a clean tree.
     */
    private const int FLOOR = 500;

    #[Test]
    public function apiSrcReadsTheCurrentInstantOnlyThroughTheClockPort(): void
    {
        $findings = [];

        foreach ($this->sourceFiles() as $relative => $path) {
            if (\array_key_exists($relative, self::EXEMPT)) {
                continue;
            }

            foreach (WallClockReads::inSource((string) \file_get_contents($path)) as $line) {
                $findings[] = \sprintf('%s:%d', $relative, $line);
            }
        }

        $this->assertSame([], $findings, \sprintf(
            'Each site below reads the current instant past the injected Clock port, so its instant can disagree '
            . 'with the one the application layer read and handed inward. Inject Erpify\Shared\Clock\Domain\Clock '
            . "into the use case or adapter, read it once per operation, and pass the instant down:\n  %s",
            \implode("\n  ", $findings),
        ));
    }

    #[Test]
    public function everyExemptionStillHoldsTheReadItExempts(): void
    {
        $files = $this->sourceFiles();

        foreach (\array_keys(self::EXEMPT) as $relative) {
            $this->assertArrayHasKey($relative, $files, \sprintf('%s is exempt but no longer exists.', $relative));
            $this->assertNotSame([], WallClockReads::inSource((string) \file_get_contents($files[$relative])), \sprintf(
                '%s is exempt but no longer reads the wall clock; remove the exemption so it cannot cover the '
                . 'next read.',
                $relative,
            ));
        }
    }

    #[Test]
    public function theSweepReadsTheTree(): void
    {
        $this->assertGreaterThan(self::FLOOR, \count($this->sourceFiles()));
    }

    /**
     * @return array<string, string> path relative to `api/` => absolute path
     */
    private function sourceFiles(): array
    {
        $apiRoot = \dirname(__DIR__, 3);
        $directory = $apiRoot . '/src';
        $this->assertDirectoryExists($directory);

        $files = [];

        /** @var SplFileInfo $file */
        foreach (ApiSourceFiles::phpFiles($directory) as $file) {
            $files[\str_replace($apiRoot . '/', '', $file->getPathname())] = $file->getPathname();
        }

        return $files;
    }
}
