<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Logging;

/**
 * Null Object implementation of the {@see Logger} port: every call is a no-op. A safe collaborator
 * for code paths (and tests) that need a logger without emitting anything.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
final class NullLogger implements Logger
{
    public function emergency(string $message, array $context = []): void
    {
    }

    public function alert(string $message, array $context = []): void
    {
    }

    public function critical(string $message, array $context = []): void
    {
    }

    public function error(string $message, array $context = []): void
    {
    }

    public function warning(string $message, array $context = []): void
    {
    }

    public function notice(string $message, array $context = []): void
    {
    }

    public function info(string $message, array $context = []): void
    {
    }

    public function debug(string $message, array $context = []): void
    {
    }

    public function log(LogLevel $level, string $message, array $context = []): void
    {
    }
}
