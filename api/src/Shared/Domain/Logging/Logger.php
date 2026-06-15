<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Logging;

/**
 * Domain-owned logging port. Application and domain code depend on this contract instead of
 * `Psr\Log\LoggerInterface`, keeping the framework's logging package out of the inner layers — the
 * single PSR-3 binding lives in {@see \Erpify\Shared\Infrastructure\Logging\PsrLogger}.
 *
 * The surface mirrors PSR-3 (the eight RFC 5424 levels plus a level-parameterised {@see log}) so
 * the adapter is a thin pass-through and callers keep the familiar API. The level argument of
 * {@see log} is the domain {@see LogLevel} enum rather than a free-form string.
 */
interface Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public function emergency(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function alert(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function notice(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function log(LogLevel $level, string $message, array $context = []): void;
}
