<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Logging;

use Erpify\Shared\Domain\Logging\Logger;
use Erpify\Shared\Domain\Logging\LogLevel;
use Psr\Log\LoggerInterface;

/**
 * The single binding of the domain {@see Logger} port to a PSR-3 sink (Monolog in this stack),
 * confining `Psr\Log` to the infrastructure layer. Which Monolog channel records the line is decided
 * by the injected {@see LoggerInterface} instance — the default `app` channel, or a dedicated
 * channel wired explicitly in the container (see `config/services.yaml`).
 */
final readonly class PsrLogger implements Logger
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->logger->emergency($message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->logger->alert($message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->logger->critical($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->logger->notice($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $this->logger->log($level->value, $message, $context);
    }
}
