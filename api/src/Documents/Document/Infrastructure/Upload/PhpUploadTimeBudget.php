<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Upload;

use Erpify\Documents\Document\Application\Upload\UploadTimeBudget;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const PHP_SAPI;

/**
 * `set_time_limit()`, which restarts PHP's counter from zero with the new value. Under FrankenPHP's threads the
 * limit is wall-clock time, blocked reads included (memlog T1-4), so a stream that heartbeats re-arms it and one
 * that stalls for longer than the limit dies — after its lease has already lapsed, so the reaper owns it.
 *
 * Inert on the CLI, where `max_execution_time` is 0: a test or a console command must never acquire a limit
 * here, since it would outlive the upload and cut the process short.
 */
#[AsAlias(UploadTimeBudget::class)]
final readonly class PhpUploadTimeBudget implements UploadTimeBudget
{
    #[Override]
    public function arm(int $seconds): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        \set_time_limit($seconds);
    }
}
